<?php

namespace App\Http\Controllers;

use App\Models\DeliveryArea;
use App\Models\DeliveryState;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Services\DeliveryPricing;
use App\Services\PaymentProcessor;
use App\Support\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    /** Checkout form (delivery details + order summary). */
    public function show()
    {
        $items = Cart::items();

        if ($items->isEmpty()) {
            return redirect()->route('cart.index')->with('error', 'Your cart is empty — add products first.');
        }

        $user = auth()->user();
        $subtotal = Cart::subtotal();

        // Final Spec §6/§7/§8 — exactly two fulfillment options; the fee is
        // priced BEFORE the order exists: pickup = free, delivery = zone or
        // state rate computed on the server.
        $deliveryOptions = ['pickup' => 'Pick Up From Our Shop', 'delivery' => 'Deliver To My Address'];
        $paymentOptions = Order::PAYMENT_OPTIONS; // Paystack only (§18)
        $defaultPayment = 'paystack';
        $defaultDelivery = old('delivery_option', 'pickup');

        // Serviceable states. Zone states (FCT/Nasarawa) expose their areas
        // with the zone fee; other states show no area field at all — the
        // state-level fee is the price (§8). An area without an active zone
        // carries fee = null so the UI can never promise a wrong price.
        $states = DeliveryState::where('status', DeliveryState::STATUS_ACTIVE)
            ->with(['areas' => fn ($q) => $q->where('status', DeliveryArea::STATUS_ACTIVE)->with('zone')])
            ->orderBy('name')
            ->get()
            ->map(function (DeliveryState $state) {
                $areas = $state->uses_zones
                    ? $state->areas
                        ->filter(fn ($area) => $area->delivery_zone_id === null
                            || ($area->zone !== null && $area->zone->isActive()))
                        ->values()
                    : collect();

                return [
                    'id' => $state->id,
                    'name' => $state->name,
                    'uses_zones' => (bool) $state->uses_zones,
                    'fee' => $state->uses_zones
                        ? null // never show the state-level price for FCT/Nasarawa (§13)
                        : ($state->default_fee !== null ? (float) $state->default_fee : null),
                    'areas' => $areas->map(fn ($area) => [
                        'id' => $area->id,
                        'name' => $area->name,
                        'fee' => $area->delivery_zone_id !== null ? (float) $area->zone->fee : null,
                        'zone' => $area->zone?->name,
                    ])->all(),
                ];
            })
            ->filter(fn ($state) => $state['fee'] !== null || $state['areas'] !== [])
            ->values();

        // Prefill from the logged-in user's profile when available.
        $old = old();
        $form = [
            'name' => $old['name'] ?? ($user->name ?? ''),
            'email' => $old['email'] ?? ($user->email ?? ''),
            'phone' => $old['phone'] ?? ($user->phone ?? ''),
            'address' => $old['address'] ?? ($user->address ?? ''),
            'notes' => $old['notes'] ?? '',
            'preferred_delivery_date' => $old['preferred_delivery_date'] ?? '',
        ];

        return view('checkout.show', compact(
            'items', 'subtotal', 'form', 'deliveryOptions', 'paymentOptions', 'defaultPayment', 'defaultDelivery', 'states'
        ));
    }

    /**
     * Place the order: validate stock → create order + items → clear cart →
     * send the browser straight to Paystack (Final Spec §3: Cart → Info →
     * Fulfillment → Fee → Total → Paystack).
     */
    public function store(Request $request)
    {
        $items = Cart::items();

        if ($items->isEmpty()) {
            return redirect()->route('cart.index')->with('error', 'Your cart is empty.');
        }

        $isDelivery = $request->input('delivery_option') === 'delivery';

        // Address + preferred date are only required for delivery orders.
        // Delivery orders must also say WHERE they are going: that choice
        // decides the fee, priced on the server below.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'delivery_option' => ['required', Rule::in(['pickup', 'delivery'])],
            'address' => $isDelivery
                ? ['required', 'string', 'min:10', 'max:500']
                : ['nullable', 'string', 'max:500'],
            'preferred_delivery_date' => $isDelivery
                ? ['required', 'date', 'after_or_equal:today']
                : ['nullable', 'date', 'after_or_equal:today'],
            'delivery_state_id' => $isDelivery
                ? ['required', 'integer']
                : ['nullable', 'integer'],
            'delivery_area_id' => ['nullable', 'integer'],
        ]);

        // Price the delivery on the server — the client's estimate is only
        // a preview and is never trusted. Not serviceable = blocked checkout.
        $quote = null;
        if ($isDelivery) {
            $state = DeliveryState::find((int) $data['delivery_state_id']);

            // FCT/Nasarawa always need their area (§6/§7).
            if ($state && $state->uses_zones && empty($data['delivery_area_id'])) {
                return back()
                    ->withInput()
                    ->withErrors(['delivery_area_id' => 'Please select your area.']);
            }

            $quote = app(DeliveryPricing::class)->resolve(
                (int) $data['delivery_state_id'],
                ! empty($data['delivery_area_id']) ? (int) $data['delivery_area_id'] : null
            );

            if ($quote === null) {
                // Zone states never fall back to a state-level price — an
                // unsupported/unconfigured area is simply unavailable (§13/§43).
                // The message sits on the field that actually caused it.
                if ($state && $state->uses_zones) {
                    return back()
                        ->withInput()
                        ->withErrors(['delivery_area_id' => 'Delivery is currently unavailable for this area. Please contact Customer Care.']);
                }

                return back()
                    ->withInput()
                    ->withErrors(['delivery_state_id' => 'We do not currently deliver to that location. Please contact Customer Care.']);
            }
        }

        // Re-check stock against the live DB (someone else may have bought it).
        $stockError = $this->checkStock($items);
        if ($stockError) {
            return redirect()->route('cart.index')->with('error', $stockError);
        }

        $subtotal = Cart::subtotal();

        try {
            $order = DB::transaction(function () use ($data, $items, $subtotal, $isDelivery, $quote) {
                $fee = $isDelivery ? $quote['fee'] : 0.0;

                $order = Order::create([
                    // MySQL requires a value; replace with HBM- number once id exists.
                    'order_number' => 'TMP-'.uniqid(),
                    'user_id' => auth()->id(),
                    'customer_name' => $data['name'],
                    'customer_email' => $data['email'],
                    'customer_phone' => $data['phone'],
                    'delivery_address' => $isDelivery
                        ? $data['address']
                        : (($data['address'] ?? '') ?: 'Customer pickup at shop'),
                    'delivery_option' => $data['delivery_option'],
                    'preferred_delivery_date' => $isDelivery ? $data['preferred_delivery_date'] : null,
                    'notes' => $data['notes'] ?? null,
                    'status' => 'pending', // awaiting Paystack payment (§20)
                    'payment_option' => 'paystack', // §18 — Paystack only
                    'payment_status' => 'unpaid',
                    'subtotal' => $subtotal,
                    // The fee is priced here, before the order exists:
                    // pickup = ₦0, delivery = zone/state rate.
                    'delivery_fee' => $fee,
                    'delivery_fee_status' => $isDelivery ? 'confirmed' : 'not_applicable',
                    'delivery_state_id' => $isDelivery ? $quote['state']->id : null,
                    'delivery_area_id' => $isDelivery ? ($quote['area']?->id) : null,
                    'delivery_zone_id' => $isDelivery ? ($quote['area']?->delivery_zone_id) : null,
                    'delivery_state_name' => $isDelivery ? $quote['state']->name : null,
                    'delivery_area_name' => $isDelivery ? ($quote['area']?->name) : null,
                    'delivery_zone_name' => $isDelivery ? ($quote['area']?->zone?->name) : null,
                    'delivery_fee_source' => $isDelivery ? $quote['source'] : null,
                    // Final total is known the moment the order is placed.
                    'total' => $subtotal + $fee,
                ]);

                $order->order_number = 'HBM-'.now()->year.'-'.str_pad($order->id, 6, '0', STR_PAD_LEFT);
                $order->save();

                // First entry of the status history (order created, awaiting payment).
                $order->statusHistory()->create([
                    'from_status' => null,
                    'to_status' => 'pending',
                    'changed_by' => auth()->id(),
                    'notes' => 'Order placed at checkout — awaiting Paystack payment',
                ]);

                // A delivery order gets its delivery record up front so staff
                // can schedule it; pickup orders get none.
                if ($isDelivery) {
                    $order->delivery()->create(['status' => 'pending']);
                }

                foreach ($items as $line) {
                    /** @var Product $p */
                    $p = $line->product;

                    // Decrement stock inside the transaction (row-level safety via where).
                    $updated = Product::where('id', $p->id)
                        ->where('stock_quantity', '>=', $line->qty)
                        ->decrement('stock_quantity', $line->qty);

                    if ($updated === 0) {
                        // Race: stock vanished mid-checkout.
                        throw new \RuntimeException('Not enough stock left for "'.$p->name.'".');
                    }

                    $order->items()->create([
                        'product_id' => $p->id,
                        'product_name' => $p->name,
                        'product_slug' => $p->slug,
                        'unit' => $p->unit,
                        'unit_price' => $p->price,
                        'quantity' => $line->qty,
                        'line_total' => $line->line_total,
                    ]);
                }

                return $order;
            });
        } catch (\RuntimeException $e) {
            return redirect()->route('cart.index')->with('error', $e->getMessage());
        }

        Cart::clear();

        // Payment rows are only created when money actually moves (Paystack
        // init here, then the callback/webhook verification). Straight off
        // to Paystack — the total is final and server-calculated (§16).
        return app(PaymentProcessor::class)->startPaystack($order);
    }

    /** Confirmation page after a successful order. */
    public function success($orderId)
    {
        // Route params arrive as strings — only accept numeric ids.
        if (! ctype_digit((string) $orderId)) {
            abort(404);
        }

        $order = Order::with('items', 'payment')->findOrFail((int) $orderId);
        $note = Setting::get('receipt_note', 'Thank you for shopping with Humphrey Building Materials.');

        return view('checkout.success', compact('order', 'note'));
    }

    /* ------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------ */

    protected function checkStock($items): ?string
    {
        foreach ($items as $line) {
            $fresh = Product::find($line->id);

            if (! $fresh || ! $fresh->isActive()) {
                Cart::remove($line->id);

                return '"'.$line->product->name.'" is no longer available and was removed from your cart.';
            }

            if ($fresh->stock_quantity < $line->qty) {
                if ($fresh->stock_quantity < 1) {
                    Cart::remove($line->id);

                    return '"'.$fresh->name.'" is now out of stock and was removed from your cart.';
                }

                // Drop the quantity to something the product actually allows:
                // never below the minimum order, always a whole step.
                $allowed = (int) $fresh->stock_quantity;
                $step = $fresh->step();
                if ($step > 1) {
                    $allowed -= $allowed % $step;
                }

                if ($allowed < $fresh->minOrder()) {
                    Cart::remove($fresh->id);

                    return '"'.$fresh->name.'" only has '.$fresh->stock_quantity
                        .' left, which is below the minimum order of '.$fresh->minOrder()
                        .' — it was removed from your cart.';
                }

                Cart::update($line->id, $allowed);

                return 'Only '.$allowed.' left for "'.$fresh->name.'" — your cart quantity was adjusted.';
            }

            // Stock is fine, but the ordering rules may have changed since adding.
            if ($error = $fresh->quantityError((int) $line->qty)) {
                Cart::remove($fresh->id);

                return $error.' The item was removed from your cart — please add it again.';
            }
        }

        return null;
    }
}
