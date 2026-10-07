<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use App\Notifications\DeliveryUpdated;
use App\Notifications\OrderStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Delivery management (Final Spec §22): the board, delivery records,
 * status filters and the delivery <-> order status link. There are no
 * delivery trips anywhere in this system (§22).
 */
class DeliveryManagementTest extends TestCase
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

    private function orderFor(User $customer, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'ORD-'.strtoupper(substr(uniqid(), -6)),
            'user_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '08031234567',
            'delivery_address' => '12 Ogui Road, Enugu',
            'delivery_option' => 'delivery',
            'preferred_delivery_date' => now()->addDay()->toDateString(),
            'payment_option' => 'paystack',
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'subtotal' => 4000,
            'delivery_fee' => 0,
            'total' => 4000,
        ], $attrs));
    }

    /* ------------------------------------------------------------------
     | Access control
     * ---------------------------------------------------------------- */

    public function test_guests_and_inventory_cannot_reach_the_board(): void
    {
        $this->get('/admin/deliveries')->assertRedirect('/login');

        $this->actingAs($this->staff(User::ROLE_INVENTORY))->get('/admin/deliveries')->assertForbidden();
    }

    public function test_sales_and_admin_can_open_the_board(): void
    {
        $this->actingAs($this->staff(User::ROLE_SALES))->get('/admin/deliveries')->assertOk();
        $this->actingAs($this->staff(User::ROLE_ADMIN))->get('/admin/deliveries')->assertOk();
    }

    public function test_the_sidebar_link_is_shown_to_the_right_roles(): void
    {
        $link = 'href="'.route('admin.deliveries.index').'"';

        $this->actingAs($this->staff(User::ROLE_SALES))->get(route('admin.dashboard'))->assertSee($link, false);
        $this->actingAs($this->staff(User::ROLE_INVENTORY))->get(route('admin.dashboard'))->assertDontSee($link, false);
    }

    /* ------------------------------------------------------------------
     | Records exist for delivery orders only
     * ---------------------------------------------------------------- */

    public function test_a_delivery_order_without_a_record_gets_one_when_the_board_opens(): void
    {
        $order = $this->orderFor($this->customer());
        $this->assertDatabaseMissing('deliveries', ['order_id' => $order->id]);

        $this->actingAs($this->staff(User::ROLE_ADMIN))->get('/admin/deliveries')->assertOk();

        $this->assertDatabaseHas('deliveries', ['order_id' => $order->id, 'status' => 'pending']);
    }

    public function test_pickup_orders_never_get_a_delivery_record(): void
    {
        $order = $this->orderFor($this->customer(), [
            'delivery_option' => 'pickup',
            'preferred_delivery_date' => null,
            'delivery_address' => 'Customer pickup at shop',
        ]);

        $this->actingAs($this->staff(User::ROLE_ADMIN))->get('/admin/deliveries')->assertOk();

        $this->assertDatabaseMissing('deliveries', ['order_id' => $order->id]);
    }

    public function test_a_cancelled_delivery_order_disappears_from_the_board(): void
    {
        $order = $this->orderFor($this->customer(), ['status' => 'cancelled']);
        $order->delivery()->create(['status' => 'pending']);

        $this->actingAs($this->staff(User::ROLE_ADMIN))
            ->get('/admin/deliveries')
            ->assertOk()
            ->assertDontSee($order->order_number);
    }

    public function test_new_delivery_orders_are_created_with_their_record(): void
    {
        // The checkout controller writes the record inside the transaction;
        // here we only prove the relation is wired the way the board expects.
        $order = $this->orderFor($this->customer());
        $delivery = $order->delivery()->create(['status' => 'pending']);

        $this->assertTrue($order->delivery->is($delivery));
        $this->assertTrue($delivery->order->is($order));
    }

    /* ------------------------------------------------------------------
     | Filters
     * ---------------------------------------------------------------- */

    public function test_search_and_status_filters(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer();
        $order = $this->orderFor($customer);
        $other = $this->orderFor($customer, ['order_number' => 'ORD-ZZZZ99']);
        $other->delivery()->create(['status' => 'delivered']);
        $order->delivery()->create(['status' => 'pending']);

        $this->actingAs($admin)->get('/admin/deliveries?search='.$order->order_number)
            ->assertViewHas('deliveries', fn ($d) => $d->total() === 1);

        $this->actingAs($admin)->get('/admin/deliveries?search=nothingmatches')
            ->assertViewHas('deliveries', fn ($d) => $d->total() === 0);

        $this->actingAs($admin)->get('/admin/deliveries?status=delivered')
            ->assertViewHas('deliveries', fn ($d) => $d->total() === 1);

        $this->actingAs($admin)->get('/admin/deliveries?status=zzz')
            ->assertStatus(302)->assertSessionHasErrors('status');
    }

    public function test_status_cards_count_each_delivery_status(): void
    {
        $order = $this->orderFor($this->customer());
        $order->delivery()->create(['status' => 'confirmed']);

        $this->actingAs($this->staff(User::ROLE_ADMIN))
            ->get('/admin/deliveries')
            ->assertViewHas('statusCounts', fn ($c) => ($c['confirmed'] ?? 0) === 1 && ($c['pending'] ?? 0) === 0);
    }

    /* ------------------------------------------------------------------
     | Trips are gone (Final Spec §22 — no delivery trips at all)
     * ---------------------------------------------------------------- */

    public function test_delivery_trip_routes_no_longer_exist(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);

        $this->actingAs($admin)->get('/admin/delivery-trips')->assertNotFound();
        $this->actingAs($admin)->get('/admin/delivery-trips/create')->assertNotFound();

        $this->actingAs($admin)
            ->post('/admin/delivery-trips', [
                'name' => 'Ikeja run',
                'trip_date' => now()->addDays(2)->toDateString(),
            ])
            ->assertNotFound();
    }

    /* ------------------------------------------------------------------
     | Managing one delivery
     * ---------------------------------------------------------------- */

    public function test_the_delivery_page_shows_the_full_picture(): void
    {
        $customer = $this->customer();
        $order = $this->orderFor($customer);
        $delivery = $order->delivery()->create(['status' => 'pending']);

        $response = $this->actingAs($this->staff(User::ROLE_SALES))
            ->get('/admin/deliveries/'.$delivery->id);

        $response->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('12 Ogui Road, Enugu')
            ->assertSee('Delivery Status');
    }

    public function test_a_missing_delivery_is_404(): void
    {
        $this->actingAs($this->staff(User::ROLE_ADMIN))
            ->get('/admin/deliveries/999999')->assertNotFound();
    }

    public function test_details_can_be_saved(): void
    {
        Notification::fake();

        $admin = $this->staff(User::ROLE_ADMIN);
        $order = $this->orderFor($this->customer());
        $delivery = $order->delivery()->create(['status' => 'pending']);

        $this->actingAs($admin)->patch('/admin/deliveries/'.$delivery->id, [
            'status' => 'confirmed',
            'confirmed_date' => now()->addDays(3)->toDateString(),
            'assigned_to' => $admin->id,
            'notes' => 'Gate code 4477',
        ])->assertRedirect(route('admin.deliveries.show', $delivery))->assertSessionHas('status');

        $delivery->refresh();
        $this->assertSame('confirmed', $delivery->status);
        $this->assertSame(now()->addDays(3)->toDateString(), $delivery->confirmed_date->toDateString());
        $this->assertSame($admin->id, $delivery->assigned_to);
        $this->assertSame('Gate code 4477', $delivery->notes);

        Notification::assertSentTo($order->user, DeliveryUpdated::class);
    }

    public function test_invalid_details_are_rejected(): void
    {
        $order = $this->orderFor($this->customer());
        $delivery = $order->delivery()->create(['status' => 'pending']);

        $this->actingAs($this->staff(User::ROLE_ADMIN))
            ->patch('/admin/deliveries/'.$delivery->id, ['status' => 'teleported'])
            ->assertSessionHasErrors('status');

        $this->actingAs($this->staff(User::ROLE_ADMIN))
            ->patch('/admin/deliveries/'.$delivery->id, ['status' => 'pending', 'assigned_to' => 99999])
            ->assertSessionHasErrors('assigned_to');

        $this->assertSame('pending', $delivery->fresh()->status);
    }

    /* ------------------------------------------------------------------
     | Order / delivery stay in step
     * ---------------------------------------------------------------- */

    public function test_marking_delivered_moves_the_order_when_the_state_machine_allows_it(): void
    {
        Notification::fake();

        $order = $this->orderFor($this->customer(), ['status' => 'out_for_delivery']);
        $delivery = $order->delivery()->create(['status' => 'out_for_delivery']);

        $this->actingAs($this->staff(User::ROLE_ADMIN))->patch('/admin/deliveries/'.$delivery->id, [
            'status' => 'delivered',
            'received_by' => 'Chidi Okonkwo',
            'confirmation_note' => 'Signed for at the gate.',
        ])->assertSessionHas('status');

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => 'out_for_delivery',
            'to_status' => 'delivered',
        ]);
        // PHASE 24 — a confirmation record was written with the delivery.
        $fresh = $delivery->fresh();
        $this->assertNotNull($fresh->delivered_at);
        $this->assertSame('Chidi Okonkwo', $fresh->received_by);
        Notification::assertSentTo($order->user, OrderStatusChanged::class);
    }

    public function test_marking_delivered_is_refused_by_the_state_machine_and_explained(): void
    {
        Notification::fake();

        $order = $this->orderFor($this->customer(), ['status' => 'confirmed']);
        $delivery = $order->delivery()->create(['status' => 'confirmed']);

        $this->actingAs($this->staff(User::ROLE_ADMIN))->patch('/admin/deliveries/'.$delivery->id, [
            'status' => 'delivered',
            'received_by' => 'The Caretaker',
        ])->assertSessionHas('status');

        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertStringContainsString('open the order', session('status'));
        Notification::assertNotSentTo($order->user, OrderStatusChanged::class);
    }

    public function test_failed_deliveries_notify_the_customer_but_leave_the_order_alone(): void
    {
        Notification::fake();

        $order = $this->orderFor($this->customer(), ['status' => 'out_for_delivery']);
        $delivery = $order->delivery()->create(['status' => 'out_for_delivery']);

        $this->actingAs($this->staff(User::ROLE_ADMIN))->patch('/admin/deliveries/'.$delivery->id, [
            'status' => 'failed',
            'failure_reason' => 'customer_unavailable',
            'rescheduled_date' => now()->addDays(3)->toDateString(),
            'notes' => 'Nobody home',
        ])->assertSessionHas('status');

        $this->assertSame('out_for_delivery', $order->fresh()->status);
        // PHASE 21/22 — the reason and the new date are part of the record.
        $fresh = $delivery->fresh();
        $this->assertSame('customer_unavailable', $fresh->failure_reason);
        $this->assertNotNull($fresh->rescheduled_date);
        Notification::assertSentTo($order->user, DeliveryUpdated::class);
    }

    public function test_changing_the_order_status_mirrors_back_into_the_delivery(): void
    {
        // 'confirmed' → 'out_for_delivery' is the delivery leg of the chain.
        $order = $this->orderFor($this->customer(), ['status' => 'confirmed']);
        $delivery = $order->delivery()->create(['status' => 'confirmed']);

        $this->actingAs($this->staff(User::ROLE_ADMIN))
            ->patch('/admin/orders/'.$order->id.'/status', ['status' => 'out_for_delivery'])
            ->assertSessionHas('status');

        $this->assertSame('out_for_delivery', $delivery->fresh()->status);
    }

    public function test_pickup_orders_have_no_delivery_to_update(): void
    {
        $order = $this->orderFor($this->customer(), [
            'delivery_option' => 'pickup',
            'preferred_delivery_date' => null,
        ]);

        $this->assertNull($order->delivery);
        $this->actingAs($this->staff(User::ROLE_ADMIN))
            ->patch('/admin/deliveries/'.($order->delivery?->id ?? 999999), ['status' => 'delivered'])
            ->assertNotFound();
    }
}
