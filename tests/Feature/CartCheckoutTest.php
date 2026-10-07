<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryArea;
use App\Models\DeliveryState;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Notifications\OrderPlaced;
use App\Notifications\PaymentInitiated;
use Database\Seeders\DeliveryZoneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Session cart and checkout: quantity rules, order creation, stock
 * reservation and the Paystack-only payment guard (Final Spec §18).
 */
class CartCheckoutTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------
     | Helpers
     * ---------------------------------------------------------------- */

    private function category(): Category
    {
        return Category::firstOrCreate(
            ['slug' => 'building-materials'],
            ['name' => 'Building Materials']
        );
    }

    private function product(array $attrs = []): Product
    {
        static $i = 0;
        $i++;

        return Product::create(array_merge([
            'category_id' => $this->category()->id,
            'name' => 'Cart Item '.$i,
            'slug' => 'cart-item-'.$i.'-'.uniqid(),
            'description' => 'Used by the cart and checkout tests.',
            'price' => 2500,
            'stock_quantity' => 10,
            'status' => 'active',
            'unit' => 'bag',
        ], $attrs));
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => User::ROLE_CUSTOMER, 'is_active' => true]);
    }

    /** Checkout is behind auth + email verification (Master Scope §4). */
    private function buyer(): User
    {
        return $this->customer();
    }

    private function checkoutPayload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Checkout Customer',
            'email' => 'checkout@example.test',
            'phone' => '08031234567',
            'delivery_option' => 'pickup',
            'payment_option' => 'paystack',
        ], $overrides);
    }

    /* ------------------------------------------------------------------
     | Cart
     * ---------------------------------------------------------------- */

    public function test_adding_a_product_puts_it_in_the_cart(): void
    {
        $product = $this->product();

        $this->post(route('cart.store'), ['product_id' => $product->id, 'qty' => 2])
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('status');

        $this->withSession(['cart' => [$product->id => 2]])
            ->get(route('cart.index'))
            ->assertOk()
            ->assertSee($product->name);
    }

    public function test_the_cart_refuses_more_than_the_shelf_holds(): void
    {
        $product = $this->product(['stock_quantity' => 4]);

        $this->post(route('cart.store'), ['product_id' => $product->id, 'qty' => 9])
            ->assertSessionHas('error');

        $this->assertGuest();
        $this->assertSame([], session('cart') ?? []);
    }

    public function test_minimum_order_and_step_rules_are_enforced(): void
    {
        $stepped = $this->product(['min_order_quantity' => 3, 'quantity_step' => 2, 'stock_quantity' => 50]);

        $this->post(route('cart.store'), ['product_id' => $stepped->id, 'qty' => 1])
            ->assertSessionHas('error');
        $this->assertSame([], session('cart') ?? []);

        $this->post(route('cart.store'), ['product_id' => $stepped->id, 'qty' => 5])
            ->assertSessionHas('error');
        $this->assertSame([], session('cart') ?? []);

        $this->post(route('cart.store'), ['product_id' => $stepped->id, 'qty' => 4])
            ->assertSessionHas('status');
    }

    public function test_a_missing_product_cannot_be_added(): void
    {
        $this->post(route('cart.store'), ['product_id' => 999999, 'qty' => 1])
            ->assertSessionHasErrors('product_id');
    }

    public function test_cart_lines_can_be_updated_removed_and_cleared(): void
    {
        $product = $this->product();

        $this->withSession(['cart' => [$product->id => 1]])
            ->patch(route('cart.update', $product->id), ['qty' => 3])
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('status');
        $this->assertSame([$product->id => 3], session('cart'));

        // Qty 0 empties the line entirely.
        $this->withSession(['cart' => [$product->id => 3]])
            ->patch(route('cart.update', $product->id), ['qty' => 0])
            ->assertSessionHas('status');
        $this->assertSame([], session('cart') ?? []);

        $this->withSession(['cart' => [$product->id => 2]])
            ->delete(route('cart.destroy', $product->id))
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('status');
        $this->assertSame([], session('cart') ?? []);

        $this->withSession(['cart' => [$product->id => 2]])
            ->delete(route('cart.clear'))
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('status');
        $this->assertSame([], session('cart') ?? []);
    }

    /* ------------------------------------------------------------------
     | Checkout guards
     * ---------------------------------------------------------------- */

    public function test_checkout_page_bounces_an_empty_cart(): void
    {
        $buyer = $this->buyer();

        $this->actingAs($buyer)->get(route('checkout.show'))
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('error');

        $this->actingAs($buyer)->post(route('checkout.store'), $this->checkoutPayload($this->product()))
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('error');
    }

    public function test_a_guest_is_sent_to_login_before_checkout(): void
    {
        $product = $this->product();

        $this->withSession(['cart' => [$product->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload($product, [
                'payment_option' => 'paystack',
            ]))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, Order::count());
    }

    public function test_paystack_is_the_only_payment_option(): void
    {
        $product = $this->product();

        // The checkout page offers Paystack and nothing else (§18) — no
        // pay-on-delivery, no partial payment, no cash choices.
        $this->actingAs($this->buyer())
            ->withSession(['cart' => [$product->id => 1]])
            ->get(route('checkout.show'))
            ->assertOk()
            ->assertSee('Paystack')
            ->assertDontSee('Pay on Delivery', false)
            ->assertDontSee('Cash on Delivery', false)
            ->assertDontSee('pay_on_delivery', false);

        $this->assertSame(['paystack'], array_keys(Order::PAYMENT_OPTIONS));

        // Even if a stale client posts an old payment option, checkout
        // simply ignores it and charges through Paystack.
        Http::fake(['*' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/fake-token',
                'access_code' => 'fake',
                'reference' => 'fake',
            ],
        ])]);

        $this->actingAs($this->buyer())
            ->withSession(['cart' => [$product->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload($product, [
                'payment_option' => 'pay_on_delivery',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('paystack', Order::firstOrFail()->payment_option);
    }

    public function test_delivering_requires_an_address_and_a_future_date(): void
    {
        $product = $this->product();

        $this->actingAs($this->buyer())
            ->withSession(['cart' => [$product->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload($product, [
                'delivery_option' => 'delivery',
                'address' => 'Too short',
                'preferred_delivery_date' => now()->subDay()->toDateString(),
            ]))
            ->assertSessionHasErrors(['address', 'preferred_delivery_date', 'delivery_state_id']);
    }

    /* ------------------------------------------------------------------
     | Happy path
     * ---------------------------------------------------------------- */

    public function test_checkout_creates_the_order_items_and_clears_the_cart(): void
    {
        Notification::fake();
        $buyer = $this->buyer();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $product = $this->product(['price' => 3000, 'stock_quantity' => 5]);

        // Paystack is initialised for real (§16) — fake the API call so the
        // redirect target is deterministic.
        Http::fake(['*' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/fake-token',
                'access_code' => 'fake',
                'reference' => 'fake',
            ],
        ])]);

        $response = $this->actingAs($buyer)
            ->withSession(['cart' => [$product->id => 2]])
            ->post(route('checkout.store'), $this->checkoutPayload($product));

        $order = Order::first();
        $this->assertNotNull($order);

        // Straight off to Paystack's hosted page — no success page before
        // the money is verified server-side (§19/§20).
        $response->assertRedirect('https://checkout.paystack.com/fake-token');

        // Number is issued after insert in the HBM-YYYY-###### format.
        $this->assertMatchesRegularExpression('/^HBM-\d{4}-\d{6}$/', $order->order_number);

        // Money maths: subtotal == total for a pickup order with no fee.
        $this->assertSame(6000.0, (float) $order->subtotal);
        $this->assertSame(6000.0, (float) $order->total);
        // A Paystack payment is in flight, so the order is "Pending" (§16).
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('pending', $order->status);

        // The Paystack payment row waits for verification.
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'method' => 'paystack',
            'status' => 'pending',
            'amount' => 6000,
        ]);

        // Line items were written and stock was reserved immediately.
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 3000,
            'line_total' => 6000,
        ]);
        $this->assertSame(3, (int) $product->fresh()->stock_quantity);

        // Cart is emptied and the placed-order history row exists.
        $this->assertSame([], session('cart') ?? []);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'to_status' => 'pending',
        ]);

        // The customer is nudged to pay; the shop owner only hears about
        // the order once payment is VERIFIED (§20 — confirmation email).
        Notification::assertSentTo($buyer, PaymentInitiated::class);
        Notification::assertNotSentTo($buyer, OrderPlaced::class);
        Notification::assertNotSentTo($admin, OrderPlaced::class);
    }

    public function test_a_delivery_checkout_also_creates_a_pending_delivery(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/fake-token',
                'access_code' => 'fake',
                'reference' => 'fake',
            ],
        ])]);
        $this->seed(DeliveryZoneSeeder::class);
        $product = $this->product();

        // Mararaba sits in Nasarawa and is mapped to Zone 1 (₦5,000).
        $state = DeliveryState::where('name', 'Nasarawa')->firstOrFail();
        $area = DeliveryArea::where('name', 'Mararaba')->firstOrFail();

        $this->actingAs($this->buyer())
            ->withSession(['cart' => [$product->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload($product, [
                'delivery_option' => 'delivery',
                'address' => '12 Marina Road, Lagos',
                'preferred_delivery_date' => now()->addDays(2)->toDateString(),
                'delivery_state_id' => $state->id,
                'delivery_area_id' => $area->id,
            ]))
            ->assertRedirect();

        $order = Order::first();
        $this->assertDatabaseHas('deliveries', [
            'order_id' => $order->id,
            'status' => 'pending',
        ]);
        $this->assertSame('12 Marina Road, Lagos', $order->delivery_address);

        // PHASE 16/17 — the fee was priced server-side at checkout from the
        // mapped zone, and the choice was snapshotted onto the order.
        $this->assertSame(5000.0, (float) $order->delivery_fee);
        $this->assertSame('confirmed', $order->delivery_fee_status);
        $this->assertSame('zone', $order->delivery_fee_source);
        $this->assertSame('Nasarawa', $order->delivery_state_name);
        $this->assertSame('Mararaba', $order->delivery_area_name);
        $this->assertSame('Zone 1', $order->delivery_zone_name);
        $this->assertSame($state->id, (int) $order->delivery_state_id);
        $this->assertSame((float) $order->subtotal + 5000.0, (float) $order->total);
        $this->assertMatchesRegularExpression('/^HBM-\d{4}-\d{6}$/', $order->order_number);
    }

    public function test_a_delivery_checkout_without_a_state_is_refused(): void
    {
        $this->seed(DeliveryZoneSeeder::class);
        $product = $this->product();

        $this->actingAs($this->buyer())
            ->withSession(['cart' => [$product->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload($product, [
                'delivery_option' => 'delivery',
                'address' => '12 Marina Road, Lagos',
                'preferred_delivery_date' => now()->addDays(2)->toDateString(),
            ]))
            ->assertSessionHasErrors('delivery_state_id');

        $this->assertSame(0, Order::count());
    }

    public function test_a_pickup_checkout_creates_no_delivery_record(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/fake-token',
                'access_code' => 'fake',
                'reference' => 'fake',
            ],
        ])]);
        $product = $this->product();

        $this->actingAs($this->buyer())
            ->withSession(['cart' => [$product->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload($product))
            ->assertRedirect();

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertDatabaseCount('deliveries', 0);
    }

    public function test_the_success_page_is_404_for_an_unknown_order(): void
    {
        $this->actingAs($this->buyer())
            ->get(route('checkout.success', 424242))
            ->assertNotFound();
    }

    public function test_the_success_page_renders_for_a_real_order(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/fake-token',
                'access_code' => 'fake',
                'reference' => 'fake',
            ],
        ])]);
        $product = $this->product();

        $this->actingAs($this->buyer())
            ->withSession(['cart' => [$product->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload($product));

        $this->actingAs($this->buyer())
            ->get(route('checkout.success', Order::first()->id))
            ->assertOk()
            ->assertSee(Order::first()->order_number);
    }
}
