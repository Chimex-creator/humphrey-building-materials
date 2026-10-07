<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use App\Notifications\DeliveryUpdated;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phases 21–24 — the delivery workflow explains itself:
 * failures carry a reason (+ reschedule), deliveries carry a confirmation
 * record (when + who received it), and every move hits the audit trail.
 */
class DeliveryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => User::ROLE_CUSTOMER, 'is_active' => true]);
    }

    private function delivery(array $orderAttrs = [], array $deliveryAttrs = []): Delivery
    {
        $customer = $this->customer();

        $order = Order::create(array_merge([
            'order_number' => 'HBM-'.strtoupper(substr(uniqid(), -6)),
            'user_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '08031234567',
            'delivery_address' => '5 Zaki Abubakar Way, Mararaba',
            'delivery_option' => 'delivery',
            'preferred_delivery_date' => now()->addDay()->toDateString(),
            'payment_option' => 'paystack',
            'status' => 'out_for_delivery',
            'payment_status' => 'unpaid',
            'subtotal' => 4000,
            'delivery_fee' => 0,
            'total' => 4000,
        ], $orderAttrs));

        return $order->delivery()->create(array_merge(
            ['status' => 'out_for_delivery'],
            $deliveryAttrs
        ));
    }

    /* ------------------------------------------------------------------
     | Failure needs a reason (P21)
     * ---------------------------------------------------------------- */

    public function test_a_delivery_cannot_fail_without_a_reason(): void
    {
        $delivery = $this->delivery();

        $this->actingAs($this->staff())
            ->patch('/admin/deliveries/'.$delivery->id, ['status' => 'failed'])
            ->assertSessionHasErrors('failure_reason');

        $this->assertSame('out_for_delivery', $delivery->fresh()->status);
    }

    public function test_the_other_reason_requires_a_note(): void
    {
        $delivery = $this->delivery();

        $this->actingAs($this->staff())
            ->patch('/admin/deliveries/'.$delivery->id, [
                'status' => 'failed',
                'failure_reason' => 'other',
            ])
            ->assertSessionHasErrors('failure_note');

        $this->actingAs($this->staff())
            ->patch('/admin/deliveries/'.$delivery->id, [
                'status' => 'failed',
                'failure_reason' => 'other',
                'failure_note' => 'Gate was padlocked and the security man had no key.',
            ])
            ->assertSessionHas('status');

        $fresh = $delivery->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('other', $fresh->failure_reason);
        $this->assertSame('Gate was padlocked and the security man had no key.', $fresh->failure_note);
    }

    /* ------------------------------------------------------------------
     | Reschedule (P22)
     * ---------------------------------------------------------------- */

    public function test_a_failure_with_a_new_date_is_rescheduled_and_audited(): void
    {
        Notification::fake();

        $delivery = $this->delivery();
        $newDate = now()->addDays(3)->toDateString();

        $this->actingAs($this->staff())
            ->patch('/admin/deliveries/'.$delivery->id, [
                'status' => 'failed',
                'failure_reason' => 'customer_unavailable',
                'rescheduled_date' => $newDate,
            ])
            ->assertSessionHas('status');

        $fresh = $delivery->fresh();
        $this->assertSame($newDate, $fresh->rescheduled_date->toDateString());

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'delivery.status_changed',
            'subject_id' => $delivery->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'delivery.rescheduled',
            'subject_id' => $delivery->id,
        ]);

        // The customer hears the reason AND the new date.
        Notification::assertSentTo($delivery->order->user, DeliveryUpdated::class,
            function (DeliveryUpdated $notification) use ($delivery, $newDate) {
                $data = $notification->toArray($delivery->order->user);

                return str_contains($data['message'], 'Customer unavailable')
                    && str_contains($data['message'], 'new date has been scheduled: '.Carbon::parse($newDate)->format('d M Y'));
            });
    }

    public function test_a_failure_without_a_new_date_promises_contact_instead(): void
    {
        Notification::fake();

        $delivery = $this->delivery();

        $this->actingAs($this->staff())
            ->patch('/admin/deliveries/'.$delivery->id, [
                'status' => 'failed',
                'failure_reason' => 'vehicle_issue',
                'failure_note' => 'Truck broke down on the Mararaba road.',
            ])
            ->assertSessionHas('status');

        Notification::assertSentTo($delivery->order->user, DeliveryUpdated::class,
            function (DeliveryUpdated $notification) use ($delivery) {
                $data = $notification->toArray($delivery->order->user);

                return str_contains($data['message'], 'contact you')
                    && str_contains($data['message'], 'Vehicle');
            });
    }

    /* ------------------------------------------------------------------
     | Delivery confirmation record (P24)
     * ---------------------------------------------------------------- */

    public function test_marking_delivered_requires_who_received_it(): void
    {
        $delivery = $this->delivery();

        $this->actingAs($this->staff())
            ->patch('/admin/deliveries/'.$delivery->id, ['status' => 'delivered'])
            ->assertSessionHasErrors('received_by');

        $this->assertSame('out_for_delivery', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->delivered_at);
    }

    public function test_a_delivered_status_writes_a_confirmation_record_once(): void
    {
        $delivery = $this->delivery();

        $this->actingAs($this->staff())
            ->patch('/admin/deliveries/'.$delivery->id, [
                'status' => 'delivered',
                'received_by' => 'Musa Bello',
                'confirmation_note' => 'Dropped 10 bags with the site foreman.',
            ])
            ->assertSessionHas('status');

        $firstStamp = $delivery->fresh()->delivered_at;
        $this->assertNotNull($firstStamp);
        $this->assertSame('Musa Bello', $delivery->fresh()->received_by);

        // A later re-save (e.g. fixing notes) must not move the original stamp.
        $this->actingAs($this->staff())
            ->patch('/admin/deliveries/'.$delivery->id, [
                'status' => 'delivered',
                'received_by' => 'Musa Bello',
                'notes' => 'Photo taken at drop-off.',
            ])
            ->assertSessionHas('status');

        $fresh = $delivery->fresh();
        $this->assertSame($firstStamp->toDateTimeString(), $fresh->delivered_at->toDateTimeString());
        $this->assertSame('Photo taken at drop-off.', $fresh->notes);
    }

    public function test_marking_delivered_from_the_order_page_also_stamps_the_record(): void
    {
        Notification::fake();

        // order (confirmed) + its delivery (ready for pickup, about to move).
        $delivery = $this->delivery(['status' => 'confirmed'], ['status' => 'ready_for_pickup']);

        $this->actingAs($this->staff())
            ->patch('/admin/orders/'.$delivery->order_id.'/status', ['status' => 'out_for_delivery'])
            ->assertSessionHas('status');
        $this->assertNull($delivery->fresh()->delivered_at);

        $this->actingAs($this->staff())
            ->patch('/admin/orders/'.$delivery->order_id.'/status', ['status' => 'delivered'])
            ->assertSessionHas('status');

        $fresh = $delivery->fresh();
        $this->assertSame('delivered', $fresh->status);
        $this->assertNotNull($fresh->delivered_at);
    }

    /* ------------------------------------------------------------------
     | The screen shows the record
     * ---------------------------------------------------------------- */

    public function test_the_delivery_screen_shows_failure_and_confirmation_records(): void
    {
        $delivery = $this->delivery([], [
            'status' => 'failed',
            'failure_reason' => 'wrong_address',
            'failure_note' => 'The address on the order led to an empty plot.',
            'rescheduled_date' => now()->addDays(2)->toDateString(),
        ]);

        $this->actingAs($this->staff())
            ->get('/admin/deliveries/'.$delivery->id)
            ->assertOk()
            ->assertSee('Failed Attempt')
            ->assertSee('Wrong / incomplete address')
            ->assertSee('empty plot');
    }
}
