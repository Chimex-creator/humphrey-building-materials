<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Notifications\DeliveryFeeConfirmed;
use App\Notifications\OrderClosed;
use App\Notifications\OrderStatusChanged;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /** List all orders with search + status filter. */
    public function index(Request $request)
    {
        $search = $request->input('search');
        $statusFilter = $request->input('status');

        $query = Order::query()->withCount('items')->latest();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        if ($statusFilter && array_key_exists($statusFilter, Order::STATUSES)) {
            $query->where('status', $statusFilter);
        }

        $orders = $query->paginate(15)->withQueryString();
        $statusCounts = Order::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.orders.index', compact('orders', 'search', 'statusFilter', 'statusCounts'));
    }

    /** Single order: customer, delivery address, items, status form. */
    public function show(Order $order)
    {
        $order->load([
            'items', 'user', 'payments', 'confirmedBy', 'statusHistory.changedBy',
            'returns.product', 'returns.returnedBy', 'returns.reviewedBy',
        ]);

        return view('admin.orders.show', compact('order'));
    }

    /** Move the order to the next allowed status (state machine). */
    public function updateStatus(Request $request, Order $order, ActivityLogger $logger)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Order::STATUSES))],
        ]);

        $next = $data['status'];

        if (! in_array($next, $order->allowedNextStatuses(), true)) {
            return back()->with('error', 'Cannot change "'.$order->statusLabel().'" to "'.(Order::STATUSES[$next] ?? $next).'".');
        }

        // PHASE 10 — an order cannot be closed while a return request is open.
        if ($next === 'closed' && $order->hasPendingReturns()) {
            $open = $order->pendingReturns()->count();

            return back()->with('error', 'Cannot close '.$order->order_number.' yet — '
                .$open.' return request'.($open === 1 ? ' is' : 's are').' still awaiting review.');
        }

        $previous = $order->status;
        $order->status = $next;
        $order->save();

        // Audit trail: who changed what, when.
        $order->statusHistory()->create([
            'from_status' => $previous,
            'to_status' => $next,
            'changed_by' => auth()->id(),
            'notes' => 'Status updated by '.auth()->user()->name,
        ]);

        // PHASE 12 — a cancellation is a sensitive action (Master Prompt §47).
        if ($next === 'cancelled' && $previous !== 'cancelled') {
            $logger->log('order.cancelled', $order, sprintf(
                'Order %s was cancelled while "%s".',
                $order->order_number,
                Order::STATUSES[$previous] ?? $previous
            ), [
                'from_status' => $previous,
            ]);
        }

        // PHASE 7/24 - when staff drives the ORDER instead of the delivery board,
        // keep the delivery record in step so the two screens agree — and still
        // stamp the moment of delivery as a confirmation record.
        $orderStatusToDelivery = ['confirmed' => 'confirmed', 'out_for_delivery' => 'out_for_delivery', 'delivered' => 'delivered'];
        if ($order->delivery && isset($orderStatusToDelivery[$next])) {
            $deliveryAttrs = ['status' => $orderStatusToDelivery[$next]];
            if ($next === 'delivered' && $order->delivery->delivered_at === null) {
                $deliveryAttrs['delivered_at'] = now();
            }
            $order->delivery->update($deliveryAttrs);
        }

        // Cancellation must give the stock back (Phase 7 — §33).
        // The state machine makes 'cancelled' terminal, so this can only run once.
        $restored = 0;
        if ($next === 'cancelled' && $previous !== 'cancelled') {
            foreach ($order->items as $item) {
                if ($item->product_id) {
                    Product::where('id', $item->product_id)
                        ->increment('stock_quantity', $item->quantity);
                    $restored++;
                }
            }
        }

        // Tell the customer what happened to their order.
        if ($order->user) {
            if ($next === 'closed') {
                $order->user->notify(new OrderClosed($order));
            } else {
                $order->user->notify(new OrderStatusChanged(
                    $order,
                    Order::STATUSES[$previous] ?? ucfirst($previous),
                    Order::STATUSES[$next] ?? ucfirst($next)
                ));
            }
        }

        $message = 'Order '.$order->order_number.' is now "'.$order->statusLabel().'".';

        if ($restored > 0) {
            $message .= ' Stock restored for '.$restored.' line item(s).';
        }

        // Money already received on a cancelled order is refunded with the
        // customer directly (Final Spec §27 — no website refunds).
        if ($next === 'cancelled' && $order->paidAmount() > 0) {
            $message .= ' ⚠ ₦'.number_format($order->paidAmount(), 0)
                .' was already received — arrange the refund with the customer directly.';
        }

        return redirect()
            ->route('admin.orders.show', $order)
            ->with('status', $message);
    }

    /**
     * Confirm (or deliberately override) the delivery fee of a DELIVERY order.
     * subtotal + fee = new total, and the customer is notified (in-app + email).
     *
     * PHASE 18 — once a fee is confirmed, changing it again is an override:
     * it needs a written reason and is written to the audit trail.
     */
    public function confirmDeliveryFee(Request $request, Order $order, ActivityLogger $logger)
    {
        if (! $order->isDelivery()) {
            return back()->with('error', 'This is a pickup order — there is no delivery fee to confirm.');
        }

        $alreadyConfirmed = $order->feeConfirmed();
        $previousFee = (float) $order->delivery_fee;

        if ($alreadyConfirmed && $order->paidAmount() > 0) {
            return back()->with('error', 'Money has already been received for this order — the fee cannot be changed now.');
        }

        // The reason is only demanded once we know this really is an
        // override (confirmed fee, different number) — checked below.
        $data = $request->validate([
            'delivery_fee' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'override_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $fee = (float) $data['delivery_fee'];

        if ($alreadyConfirmed && $fee === $previousFee) {
            return back()->with('error', 'The delivery fee is already ₦'.number_format($fee, 0).' — nothing to change.');
        }

        $reason = trim((string) ($data['override_reason'] ?? ''));

        if ($alreadyConfirmed && mb_strlen($reason) < 5) {
            return back()
                ->withInput()
                ->withErrors([
                    'override_reason' => 'Changing a confirmed delivery fee needs a reason — write why you are overriding it.',
                ]);
        }

        $order->delivery_fee = $fee;
        $order->delivery_fee_status = 'confirmed';
        $order->delivery_fee_source = 'manual';
        $order->delivery_fee_note = $reason !== '' ? $reason : $order->delivery_fee_note;
        $order->confirmed_by = auth()->id();
        $order->confirmed_at = now();
        $order->total = (float) $order->subtotal + $fee; // subtotal + fee = final total
        $order->save();

        // PHASE 18 — audit both the first confirmation and every override.
        $logger->log('delivery.fee_overridden', $order,
            ($alreadyConfirmed ? 'Delivery fee overridden on ' : 'Delivery fee confirmed on ').$order->order_number
            .': ₦'.number_format($previousFee, 0).' → ₦'.number_format($fee, 0)
            .($reason !== '' ? ' — '.$reason : ''),
            [
                'from' => $previousFee,
                'to' => $fee,
                'override' => $alreadyConfirmed,
                'reason' => $reason ?: null,
            ]);

        // Notify the customer so they can go pay the final amount.
        if ($order->user) {
            $order->user->notify(new DeliveryFeeConfirmed($order, $fee));
        }

        return back()->with(
            'status',
            ($alreadyConfirmed ? 'Delivery fee overridden' : 'Delivery fee of ₦'.number_format($fee, 0)).' for '.$order->order_number
            .($alreadyConfirmed ? ' (now ₦'.number_format($fee, 0).')' : '')
            .'. New total: ₦'.number_format((float) $order->total, 0).'. Customer notified.'
        );
    }
}
