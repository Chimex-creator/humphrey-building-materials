<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 6 — delivery assignment accepts a free-text name.
 *
 * A delivery is taken by EITHER a staff account (the dropdown) OR any
 * named person such as an external courier (free text). The dropdown wins
 * when both are supplied; choosing neither clears the assignment.
 */
class DeliveryAssignmentTest extends TestCase
{
    use RefreshDatabase;

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

    private function delivery(array $attrs = []): Delivery
    {
        $order = $this->orderFor($this->customer());

        return $order->delivery()->create(array_merge(['status' => 'pending'], $attrs));
    }

    /* ------------------------------------------------------------------
     | Free-text assignment (external couriers)
     * ---------------------------------------------------------------- */

    public function test_a_free_text_courier_name_can_be_saved(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $delivery = $this->delivery();

        $this->actingAs($admin)
            ->patch(route('admin.deliveries.update', $delivery), [
                'status' => 'confirmed',
                'assigned_name' => 'GIG Logistics — Chidi',
            ])
            ->assertSessionHas('status');

        $fresh = $delivery->fresh();
        $this->assertSame('GIG Logistics — Chidi', $fresh->assigned_name);
        $this->assertNull($fresh->assigned_to);
        $this->assertSame('GIG Logistics — Chidi', $fresh->assignedPersonLabel());
    }

    public function test_a_free_text_name_longer_than_the_limit_is_rejected(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $delivery = $this->delivery();

        $this->actingAs($admin)
            ->patch(route('admin.deliveries.update', $delivery), [
                'status' => 'confirmed',
                'assigned_name' => str_repeat('x', 101),
            ])
            ->assertSessionHasErrors('assigned_name');

        $this->assertNull($delivery->fresh()->assigned_name);
    }

    /* ------------------------------------------------------------------
     | Dropdown vs free text
     * ---------------------------------------------------------------- */

    public function test_the_staff_dropdown_wins_over_free_text(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $delivery = $this->delivery();

        $this->actingAs($admin)
            ->patch(route('admin.deliveries.update', $delivery), [
                'status' => 'confirmed',
                'assigned_to' => $admin->id,
                'assigned_name' => 'Ghost Courier',
            ])
            ->assertSessionHas('status');

        $fresh = $delivery->fresh();
        $this->assertSame($admin->id, (int) $fresh->assigned_to);
        $this->assertNull($fresh->assigned_name, 'The dropdown wins — the free-text name must not stick.');
        $this->assertSame($admin->name, $fresh->assignedPersonLabel());
    }

    public function test_choosing_neither_clears_the_whole_assignment(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $courier = $this->staff(User::ROLE_SALES);
        $delivery = $this->delivery(['assigned_to' => $courier->id]);

        $this->actingAs($admin)
            ->patch(route('admin.deliveries.update', $delivery), [
                'status' => 'confirmed',
                'assigned_to' => null,
                'assigned_name' => '',
            ])
            ->assertSessionHas('status');

        $fresh = $delivery->fresh();
        $this->assertNull($fresh->assigned_to);
        $this->assertNull($fresh->assigned_name);
        $this->assertNull($fresh->assignedPersonLabel());
    }

    public function test_switching_from_staff_to_free_text_clears_the_staff_link(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $courier = $this->staff(User::ROLE_SALES);
        $delivery = $this->delivery(['assigned_to' => $courier->id]);

        $this->actingAs($admin)
            ->patch(route('admin.deliveries.update', $delivery), [
                'status' => 'confirmed',
                'assigned_to' => null,
                'assigned_name' => 'DHL Express',
            ])
            ->assertSessionHas('status');

        $fresh = $delivery->fresh();
        $this->assertNull($fresh->assigned_to);
        $this->assertSame('DHL Express', $fresh->assigned_name);
        $this->assertSame('DHL Express', $fresh->assignedPersonLabel());
    }

    /* ------------------------------------------------------------------
     | The label helpers agree with what is stored
     * ---------------------------------------------------------------- */

    public function test_the_label_prefers_the_staff_name_when_both_somehow_exist(): void
    {
        $courier = $this->staff(User::ROLE_SALES);
        // Written directly: the controller never produces this state, but the
        // label must still prefer the linked staff account if it ever appears.
        $delivery = $this->delivery([
            'assigned_to' => $courier->id,
            'assigned_name' => 'Stale Free Text',
        ]);

        $this->assertSame($courier->name, $delivery->assignedPersonLabel());
    }

    public function test_the_free_text_name_is_shown_on_the_delivery_board(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $delivery = $this->delivery(['assigned_name' => 'Musa — Private Courier']);

        $this->actingAs($admin)
            ->get(route('admin.deliveries.index'))
            ->assertOk()
            ->assertSee('Musa — Private Courier');
    }

    public function test_the_free_text_name_is_shown_on_the_delivery_page(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $delivery = $this->delivery(['assigned_name' => 'Musa — Private Courier']);

        $this->actingAs($admin)
            ->get(route('admin.deliveries.show', $delivery))
            ->assertOk()
            ->assertSee('Musa — Private Courier');
    }
}
