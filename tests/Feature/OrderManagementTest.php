<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Notifications\DeliveryFeeConfirmed;
use App\Notifications\OrderStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Customer order history plus the admin order desk: legal status moves,
 * cancellation stock restore, delivery-fee confirmation and cash recording.
 */
class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------
     | Helpers
     * ---------------------------------------------------------------- */

    private function staff(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => User::ROLE_CUSTOMER, 'is_active' => true]);
    }

    private function product(array $attrs = []): Product
    {
        static $i = 0;
        $i++;

        return Product::create(array_merge([
            'category_id' => $this->categoryId(),
            'name' => 'Desk Item '.$i,
            'slug' => 'desk-item-'.$i.'-'.uniqid(),
            'description' => 'Used by the order desk tests.',
            'price' => 2000,
            'stock_quantity' => 50,
            'status' => 'active',
            'unit' => 'bag',
        ], $attrs));
    }

    private function categoryId(): int
    {
        static $id = 0;
        if (! $id) {
            $id = Category::firstOrCreate(
                ['slug' => 'order-desk'],
                ['name' => 'Order Desk']
            )->id;
        }

        return $id;
    }

    private function orderFor(User $customer, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'ORD-'.strtoupper(substr(uniqid(), -6)),
            'user_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '08031234567',
            'delivery_address' => '',
            'delivery_option' => 'pickup',
            'preferred_delivery_date' => now()->addDay()->toDateString(),
            'payment_option' => 'pay_on_delivery',
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'subtotal' => 4000,
            'delivery_fee' => 0,
            'total' => 4000,
        ], $overrides));
    }

    /* ------------------------------------------------------------------
     | Customer side
     * ---------------------------------------------------------------- */

    public function test_a_customer_only_sees_their_own_orders(): void
    {
        $mine = $this->customer();
        $other = $this->customer();
        $myOrder = $this->orderFor($mine, ['order_number' => 'HBM-2026-000111']);
        $this->orderFor($other, ['order_number' => 'HBM-2026-000222']);

        $this->actingAs($mine)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee('HBM-2026-000111')
            ->assertDontSee('HBM-2026-000222');

        $this->actingAs($mine)
            ->get(route('orders.show', $myOrder))
            ->assertOk()
            ->assertSee('HBM-2026-000111');
    }

    /* ------------------------------------------------------------------
     | Admin desk
     * ---------------------------------------------------------------- */

    public function test_the_order_desk_is_for_sales_and_admin(): void
    {
        $this->get(route('admin.orders.index'))->assertRedirect(route('login'));

        $inventory = $this->staff(User::ROLE_INVENTORY);
        $this->actingAs($inventory)->get(route('admin.orders.index'))->assertForbidden();

        $sales = $this->staff(User::ROLE_SALES);
        $this->actingAs($sales)->get(route('admin.orders.index'))->assertOk();
        $this->actingAs($sales)->get(route('admin.orders.show', $this->orderFor($this->customer())))->assertOk();
    }

    public function test_a_missing_order_is_404_on_the_admin_desk(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);

        $this->actingAs($admin)->get(route('admin.orders.show', 999999))->assertNotFound();
    }

    public function test_moving_an_order_forward_writes_history_and_notifies_the_customer(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer();
        // 'confirmed' is set by Paystack verification (§20); staff then run
        // the fulfillment chain — here: the pickup leg.
        $order = $this->orderFor($customer, ['status' => 'confirmed']);

        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $order), ['status' => 'ready_for_pickup'])
            ->assertRedirect(route('admin.orders.show', $order))
            ->assertSessionHas('status');

        $this->assertSame('ready_for_pickup', $order->fresh()->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => 'confirmed',
            'to_status' => 'ready_for_pickup',
            'changed_by' => $admin->id,
        ]);
        Notification::assertSentTo($customer, OrderStatusChanged::class);
    }

    public function test_an_illegal_status_jump_is_refused_with_a_reason(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $order = $this->orderFor($this->customer());

        // pending -> delivered skips the whole state machine.
        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $order), ['status' => 'delivered'])
            ->assertSessionHas('error');

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertDatabaseCount('order_status_histories', 0);

        // An unknown status never passes validation.
        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $order), ['status' => 'teleported'])
            ->assertSessionHasErrors('status');
    }

    public function test_cancelling_restores_stock_and_is_audited(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $product = $this->product(['stock_quantity' => 10]);
        $order = $this->orderFor($this->customer());

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price' => 2000,
            'quantity' => 4,
            'line_total' => 8000,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $order), ['status' => 'cancelled'])
            ->assertSessionHas('status');

        $this->assertSame(14, (int) $product->fresh()->stock_quantity);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'order.cancelled',
            'subject_id' => $order->id,
        ]);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_search_and_status_filters_on_the_order_desk(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer();
        $placed = $this->orderFor($customer, ['order_number' => 'HBM-2026-777001', 'customer_name' => 'Babatunde Jones']);
        $this->orderFor($customer, ['order_number' => 'HBM-2026-777002', 'status' => 'delivered']);

        $this->actingAs($admin)
            ->get(route('admin.orders.index', ['search' => 'HBM-2026-777001']))
            ->assertOk()
            ->assertSee('HBM-2026-777001')
            ->assertDontSee('HBM-2026-777002');

        $this->actingAs($admin)
            ->get(route('admin.orders.index', ['search' => 'Babatunde']))
            ->assertOk()
            ->assertSee($placed->order_number);

        $this->actingAs($admin)
            ->get(route('admin.orders.index', ['status' => 'delivered']))
            ->assertOk()
            ->assertSee('HBM-2026-777002')
            ->assertDontSee('HBM-2026-777001');
    }

    /* ------------------------------------------------------------------
     | Delivery fee
     * ---------------------------------------------------------------- */

    public function test_confirming_a_delivery_fee_recalculates_the_total(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer();
        $order = $this->orderFor($customer, [
            'delivery_option' => 'delivery',
            'delivery_address' => '12 Marina Road, Lagos',
            'delivery_fee' => 1500,
            'delivery_fee_status' => 'pending_confirmation',
            'total' => 4000,
        ]);
        Delivery::create(['order_id' => $order->id, 'status' => 'pending']);

        $this->actingAs($admin)
            ->post(route('admin.orders.delivery-fee', $order), ['delivery_fee' => 2500])
            ->assertSessionHas('status');

        $fresh = $order->fresh();
        $this->assertSame('confirmed', $fresh->delivery_fee_status);
        $this->assertSame(2500.0, (float) $fresh->delivery_fee);
        $this->assertSame(6500.0, (float) $fresh->total);
        $this->assertSame($admin->id, (int) $fresh->confirmed_by);
        Notification::assertSentTo($customer, DeliveryFeeConfirmed::class);

        // Confirming twice is refused.
        $this->actingAs($admin)
            ->post(route('admin.orders.delivery-fee', $order), ['delivery_fee' => 2500])
            ->assertSessionHas('error');
    }

    public function test_pickup_orders_have_no_delivery_fee_to_confirm(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $order = $this->orderFor($this->customer());

        $this->actingAs($admin)
            ->post(route('admin.orders.delivery-fee', $order), ['delivery_fee' => 1000])
            ->assertSessionHas('error');
    }

    /* ------------------------------------------------------------------
     | Money only moves through Paystack (Final Spec §18)
     * ---------------------------------------------------------------- */

    public function test_recording_a_payment_by_hand_is_gone(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $order = $this->orderFor($this->customer());

        // The old manual-recording endpoint no longer exists at all.
        $this->actingAs($admin)
            ->post('/admin/orders/'.$order->id.'/record-payment', [
                'amount' => 100,
                'method' => 'manual',
            ])
            ->assertNotFound();

        $this->assertSame(0, Payment::count());
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }
}
