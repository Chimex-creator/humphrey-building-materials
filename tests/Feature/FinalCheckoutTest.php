<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryArea;
use App\Models\DeliveryState;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DeliveryZoneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Final Spec §57 — the final checkout test cases A–H:
 * pickup chain, FCT/Nasarawa zone pricing, other-state pricing,
 * unsupported areas, price tampering, unverified return from Paystack
 * and historical fee retention.
 */
class FinalCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(DeliveryZoneSeeder::class);
        config([
            'services.paystack.key' => 'sk_test_fake_secret_key',
            'services.paystack.public_key' => 'pk_test_fake_public_key',
            'services.paystack.url' => 'https://api.paystack.co',
        ]);
    }

    /* ------------------------------------------------------------------
     | Helpers
     * ---------------------------------------------------------------- */

    private function customer(): User
    {
        return User::factory()->create(['role' => User::ROLE_CUSTOMER, 'is_active' => true]);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    private function product(array $attrs = []): Product
    {
        static $i = 0;
        $i++;

        return Product::create(array_merge([
            'category_id' => Category::firstOrCreate(
                ['slug' => 'building-materials'],
                ['name' => 'Building Materials']
            )->id,
            'name' => 'Final Item '.$i,
            'slug' => 'final-item-'.$i.'-'.uniqid(),
            'description' => 'Used by the final checkout tests.',
            'price' => 3000,
            'stock_quantity' => 20,
            'status' => 'active',
            'unit' => 'bag',
        ], $attrs));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Final Test Customer',
            'email' => 'final@example.test',
            'phone' => '08031234567',
            'delivery_option' => 'pickup',
        ], $overrides);
    }

    /** Paystack online: init succeeds; verify echoes OUR reference + amount. */
    private function fakePaystack(bool $verifySucceeds = true): void
    {
        Http::fake(function ($request) use ($verifySucceeds) {
            $url = (string) $request->url();

            if (str_contains($url, '/transaction/initialize')) {
                return Http::response([
                    'status' => true,
                    'data' => [
                        'authorization_url' => 'https://checkout.paystack.com/fake-token',
                        'access_code' => 'fake',
                        'reference' => 'fake',
                    ],
                ]);
            }

            if (str_contains($url, '/transaction/verify/')) {
                if (! $verifySucceeds) {
                    return Http::response(
                        ['status' => false, 'message' => 'Transaction reference not found'],
                        404
                    );
                }

                $reference = rawurldecode(basename(parse_url($url, PHP_URL_PATH)));
                $payment = Payment::where('reference', $reference)->first();

                return Http::response([
                    'status' => true,
                    'data' => [
                        'status' => 'success',
                        'reference' => $reference,
                        'amount' => (int) round(((float) $payment->amount) * 100),
                        'currency' => 'NGN',
                        'id' => 987654,
                        'channel' => 'card',
                    ],
                ]);
            }

            return Http::response(['status' => false], 404);
        });
    }

    /** Place an order through the real checkout (pickups/deliveries both). */
    private function checkout(User $buyer, Product $product, array $overrides = [], int $qty = 2): Order
    {
        $response = $this->actingAs($buyer)
            ->withSession(['cart' => [$product->id => $qty]])
            ->post(route('checkout.store'), $this->payload($overrides));

        $response->assertRedirect('https://checkout.paystack.com/fake-token');

        return Order::firstOrFail();
    }

    /** Drive the browser callback → server-side verification. */
    private function returnFromPaystack(User $buyer, Order $order): void
    {
        $reference = Payment::where('order_id', $order->id)->firstOrFail()->reference;

        $this->actingAs($buyer)
            ->get(route('paystack.callback', ['reference' => $reference]));
    }

    /* ------------------------------------------------------------------
     | Test A — Pickup: ₦0 → Paystack → verified → full pickup chain
     * ---------------------------------------------------------------- */

    public function test_a_pickup_order_flows_from_cart_to_picked_up(): void
    {
        $this->fakePaystack();
        $buyer = $this->customer();
        $product = $this->product();
        $admin = $this->staff();

        $order = $this->checkout($buyer, $product);

        // ₦0 delivery, totals straight from the cart.
        $this->assertSame(0.0, (float) $order->delivery_fee);
        $this->assertSame('not_applicable', $order->delivery_fee_status);
        $this->assertSame(6000.0, (float) $order->total);
        $this->assertSame('pending', $order->status);

        // Server-side verification confirms the order (§19/§20).
        $this->returnFromPaystack($buyer, $order);
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('confirmed', $order->status);

        // The pickup chain runs forward, one step at a time.
        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $order), ['status' => 'ready_for_pickup'])
            ->assertSessionHas('status');
        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $order), ['status' => 'picked_up'])
            ->assertSessionHas('status');

        $this->assertSame('picked_up', $order->fresh()->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => 'pending',
        ]);
    }

    /* ------------------------------------------------------------------
     | Test B — FCT delivery: area → zone → fee → delivery chain
     * ---------------------------------------------------------------- */

    public function test_fct_delivery_prices_the_zone_and_runs_the_delivery_chain(): void
    {
        $this->fakePaystack();
        $buyer = $this->customer();
        $product = $this->product();
        $admin = $this->staff();

        $state = DeliveryState::where('name', 'Federal Capital Territory')->firstOrFail();
        $nyanya = DeliveryArea::where('name', 'Nyanya')->where('delivery_state_id', $state->id)->firstOrFail();

        $order = $this->checkout($buyer, $product, [
            'delivery_option' => 'delivery',
            'address' => '11 Nyanya Road, Abuja',
            'preferred_delivery_date' => now()->addDay()->toDateString(),
            'delivery_state_id' => $state->id,
            'delivery_area_id' => $nyanya->id,
        ], qty: 1);

        // Correct zone selected automatically, correct zone fee, correct total.
        $this->assertSame('zone', $order->delivery_fee_source);
        $this->assertSame('Zone 1', $order->delivery_zone_name);
        $this->assertSame('Nyanya', $order->delivery_area_name);
        $this->assertSame(5000.0, (float) $order->delivery_fee);
        $this->assertSame(3000.0 + 5000.0, (float) $order->total);
        $this->assertSame('confirmed', $order->delivery_fee_status);
        $this->assertNotNull($order->delivery);

        // Paystack → verification → confirmed → out for delivery → delivered.
        $this->returnFromPaystack($buyer, $order);
        $this->assertSame('confirmed', $order->fresh()->status);

        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $order), ['status' => 'out_for_delivery'])
            ->assertSessionHas('status');
        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $order), ['status' => 'delivered'])
            ->assertSessionHas('status');

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame('delivered', $order->delivery()->firstOrFail()->status);
    }

    /* ------------------------------------------------------------------
     | Test C — Nasarawa delivery: Lafia → Zone 10 → ₦20,000
     * ---------------------------------------------------------------- */

    public function test_nasarawa_delivery_prices_the_right_zone(): void
    {
        $this->fakePaystack();
        $buyer = $this->customer();
        $product = $this->product();

        $state = DeliveryState::where('name', 'Nasarawa')->firstOrFail();
        $lafia = DeliveryArea::where('name', 'Lafia')->where('delivery_state_id', $state->id)->firstOrFail();

        $order = $this->checkout($buyer, $product, [
            'delivery_option' => 'delivery',
            'address' => '1 Lafia Way, Lafia',
            'preferred_delivery_date' => now()->addDay()->toDateString(),
            'delivery_state_id' => $state->id,
            'delivery_area_id' => $lafia->id,
        ], qty: 2);

        $this->assertSame('zone', $order->delivery_fee_source);
        $this->assertSame('Zone 10', $order->delivery_zone_name);
        $this->assertSame(20000.0, (float) $order->delivery_fee);
        $this->assertSame(6000.0 + 20000.0, (float) $order->total);

        $this->returnFromPaystack($buyer, $order);
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    /* ------------------------------------------------------------------
     | Test D — Other state (Enugu): no area needed, state-level fee
     * ---------------------------------------------------------------- */

    public function test_another_state_pays_its_state_level_fee(): void
    {
        $this->fakePaystack();
        $buyer = $this->customer();
        $product = $this->product();

        $state = DeliveryState::where('name', 'Enugu')->firstOrFail();

        $order = $this->checkout($buyer, $product, [
            'delivery_option' => 'delivery',
            'address' => '12 Ogui Road, Enugu',
            'preferred_delivery_date' => now()->addDay()->toDateString(),
            'delivery_state_id' => $state->id,
            'delivery_area_id' => '',
        ], qty: 1);

        $this->assertSame('state', $order->delivery_fee_source);
        $this->assertNull($order->delivery_zone_name);
        $this->assertSame(40000.0, (float) $order->delivery_fee);
        $this->assertSame(3000.0 + 40000.0, (float) $order->total);

        $this->returnFromPaystack($buyer, $order);
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    /* ------------------------------------------------------------------
     | Test E — Unsupported FCT/Nasarawa area: never invent a fee
     * ---------------------------------------------------------------- */

    public function test_an_unsupported_zone_area_shows_delivery_unavailable(): void
    {
        $this->fakePaystack();
        $buyer = $this->customer();
        $product = $this->product();

        $state = DeliveryState::where('name', 'Federal Capital Territory')->firstOrFail();

        // A real FCT area that no zone claims (misconfigured / removed).
        $orphan = DeliveryArea::create([
            'delivery_state_id' => $state->id,
            'name' => 'Unmapped FCT Area',
            'status' => DeliveryArea::STATUS_ACTIVE,
            'delivery_zone_id' => null,
        ]);

        $this->actingAs($buyer)
            ->withSession(['cart' => [$product->id => 1]])
            ->post(route('checkout.store'), $this->payload([
                'delivery_option' => 'delivery',
                'address' => 'Somewhere unconfigured, Abuja',
                'preferred_delivery_date' => now()->addDay()->toDateString(),
                'delivery_state_id' => $state->id,
                'delivery_area_id' => $orphan->id,
            ]))
            ->assertSessionHasErrors([
                'delivery_area_id' => 'Delivery is currently unavailable for this area. Please contact Customer Care.',
            ]);

        // No fee was invented: no order exists at all.
        $this->assertSame(0, Order::count());
    }

    /* ------------------------------------------------------------------
     | Test F — Price tampering: client totals are never trusted
     * ---------------------------------------------------------------- */

    public function test_client_side_fee_and_total_tampering_is_recalculated(): void
    {
        $this->fakePaystack();
        $buyer = $this->customer();
        $product = $this->product();

        $state = DeliveryState::where('name', 'Federal Capital Territory')->firstOrFail();
        $nyanya = DeliveryArea::where('name', 'Nyanya')->where('delivery_state_id', $state->id)->firstOrFail();

        $order = $this->checkout($buyer, $product, [
            'delivery_option' => 'delivery',
            'address' => '11 Nyanya Road, Abuja',
            'preferred_delivery_date' => now()->addDay()->toDateString(),
            'delivery_state_id' => $state->id,
            'delivery_area_id' => $nyanya->id,
            // Hostile inputs: the server ignores them completely.
            'delivery_fee' => 1,
            'subtotal' => 1,
            'total' => 3,
        ], qty: 1);

        $this->assertSame(5000.0, (float) $order->delivery_fee);
        $this->assertSame(3000.0, (float) $order->subtotal);
        $this->assertSame(8000.0, (float) $order->total);
    }

    /* ------------------------------------------------------------------
     | Test G — Returning from Paystack without verification confirms nothing
     * ---------------------------------------------------------------- */

    public function test_returning_without_verification_does_not_confirm_the_order(): void
    {
        $this->fakePaystack(verifySucceeds: false);
        $buyer = $this->customer();
        $product = $this->product();

        $order = $this->checkout($buyer, $product);
        $payment = Payment::where('order_id', $order->id)->firstOrFail();

        $response = $this->actingAs($buyer)
            ->get(route('paystack.callback', ['reference' => $payment->reference]));

        // Straight back to the payment page — never the success page.
        $response->assertRedirect(route('orders.payment', $order));

        $order->refresh();
        $this->assertSame('pending', $order->status);
        $this->assertNotSame('paid', $order->payment_status);
        $this->assertNotSame('paid', $payment->fresh()->status);
    }

    /* ------------------------------------------------------------------
     | Test H — Historical price: an existing order keeps its own fee
     * ---------------------------------------------------------------- */

    public function test_changing_the_configured_price_does_not_reprice_existing_orders(): void
    {
        $this->fakePaystack();
        $buyer = $this->customer();
        $product = $this->product();

        $state = DeliveryState::where('name', 'Federal Capital Territory')->firstOrFail();
        $nyanya = DeliveryArea::where('name', 'Nyanya')->where('delivery_state_id', $state->id)->firstOrFail();

        $order = $this->checkout($buyer, $product, [
            'delivery_option' => 'delivery',
            'address' => '11 Nyanya Road, Abuja',
            'preferred_delivery_date' => now()->addDay()->toDateString(),
            'delivery_state_id' => $state->id,
            'delivery_area_id' => $nyanya->id,
        ], qty: 1);

        $this->assertSame(5000.0, (float) $order->delivery_fee);

        // The admin re-prices Zone 1 after the order was placed.
        $nyanya->zone->update(['fee' => 99999]);
        $state->update(['default_fee' => 88888]);

        $order->refresh();
        $this->assertSame(5000.0, (float) $order->delivery_fee);
        $this->assertSame(3000.0 + 5000.0, (float) $order->total);
        $this->assertSame('Zone 1', $order->delivery_zone_name);
        $this->assertSame('confirmed', $order->delivery_fee_status);
    }
}
