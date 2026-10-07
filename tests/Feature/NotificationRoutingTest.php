<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PendingRegistration;
use App\Models\ProductReturn;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\DeliveryFeeConfirmed;
use App\Notifications\DeliveryUpdated;
use App\Notifications\OrderClosed;
use App\Notifications\OrderPlaced;
use App\Notifications\OrderStatusChanged;
use App\Notifications\PasswordChanged;
use App\Notifications\PaymentInitiated;
use App\Notifications\PaymentStatusUpdated;
use App\Notifications\ReturnStatusUpdated;
use App\Notifications\SupportTicketUpdated;
use App\Notifications\VerifyPendingRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * NOTIFICATION EMAIL REDUCTION — channel routing.
 *
 * EMAIL  = critical / security / financial events only.
 * IN-APP = routine day-to-day business notifications (no email).
 */
class NotificationRoutingTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------
     | Helpers
     * ---------------------------------------------------------------- */

    private function customer(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_CUSTOMER,
            'is_active' => true,
        ], $attrs));
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    private function orderFor(User $customer, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'HBM-'.strtoupper(substr(uniqid(), -6)),
            'user_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '08031234567',
            'delivery_address' => '12 Ogui Road, Enugu',
            'delivery_option' => 'delivery',
            'preferred_delivery_date' => now()->addDay()->toDateString(),
            'payment_option' => 'pay_on_delivery',
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'subtotal' => 4000,
            'delivery_fee' => 0,
            'total' => 4000,
        ], $attrs));
    }

    /* ------------------------------------------------------------------
     | Channel routing (unit level — via() decides email vs in-app)
     * ---------------------------------------------------------------- */

    public function test_routine_notifications_are_in_app_only(): void
    {
        $customer = $this->customer();
        $order = $this->orderFor($customer);

        $routine = [
            'order status change' => new OrderStatusChanged($order, 'Processing', 'Ready for pickup'),
            'delivery update (incl. failed/rescheduled/delivered)' => new DeliveryUpdated($order, 'Delivery Failed', 'msg'),
            'delivery fee confirmed' => new DeliveryFeeConfirmed($order, 5000.0),
            'order closed' => new OrderClosed($order),
            'customer care update' => new SupportTicketUpdated(new SupportTicket),
            'return progress / refund requested' => new ReturnStatusUpdated(new ProductReturn),
            'payment initiated' => new PaymentInitiated($order, 1000.0),
            'payment failed / method selected' => new PaymentStatusUpdated('Payment Failed', 'msg', $order),
            'new order (staff alert)' => new OrderPlaced($order, true),
        ];

        foreach ($routine as $label => $notification) {
            $this->assertSame(
                ['database'],
                $notification->via($customer),
                "Expected in-app only (no email) for: {$label}"
            );
        }
    }

    public function test_order_cancellation_is_in_app_only(): void
    {
        $customer = $this->customer();
        $order = $this->orderFor($customer, ['status' => 'cancelled']);

        $notification = new OrderStatusChanged($order, 'Confirmed', 'Cancelled');

        $this->assertSame(['database'], $notification->via($customer));
        $this->assertSame('Order Cancelled', $notification->toArray($customer)['title']);
    }

    public function test_critical_notifications_still_send_email(): void
    {
        $customer = $this->customer();
        $order = $this->orderFor($customer);

        $critical = [
            'order confirmation (after verified payment)' => new OrderPlaced($order),
            'payment receipt (money in)' => new PaymentStatusUpdated('Order Fully Paid', 'msg', $order, true),
            'password changed (security)' => new PasswordChanged,
        ];

        foreach ($critical as $label => $notification) {
            $this->assertSame(
                ['database', 'mail'],
                $notification->via($customer),
                "Expected in-app + email for: {$label}"
            );
        }

        // Email verification has no in-app home (no account yet) — mail only.
        $pending = new PendingRegistration(['email' => 'guest@example.com', 'name' => 'Guest']);
        $this->assertSame(['mail'], (new VerifyPendingRegistration($pending))->via($customer));
    }

    /* ------------------------------------------------------------------
     | End to end (Notification fake captures the resolved channels)
     * ---------------------------------------------------------------- */

    public function test_status_change_reaches_the_in_app_bell_without_email(): void
    {
        Notification::fake();
        $admin = $this->staff();
        $customer = $this->customer();
        $order = $this->orderFor($customer, ['status' => 'confirmed']);

        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $order), ['status' => 'out_for_delivery'])
            ->assertSessionHas('status');

        Notification::assertSentTo($customer, OrderStatusChanged::class,
            fn ($notification, $channels) => $channels === ['database']);
    }

    public function test_cancelling_an_order_sends_no_email(): void
    {
        Notification::fake();
        $admin = $this->staff();
        $customer = $this->customer();
        $order = $this->orderFor($customer);

        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $order), ['status' => 'cancelled'])
            ->assertSessionHas('status');

        Notification::assertSentTo($customer, OrderStatusChanged::class,
            fn ($notification, $channels) => $channels === ['database']);
    }

    public function test_a_verified_payment_emails_a_receipt_exactly_once(): void
    {
        Notification::fake();

        config([
            'services.paystack.key' => 'sk_test_fake_secret_key',
            'services.paystack.url' => 'https://api.paystack.co',
        ]);

        $customer = $this->customer();
        $order = $this->orderFor($customer);
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => (float) $order->total,
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => 'HBMNOTIFREF123456',
        ]);

        Http::fake([
            'https://api.paystack.co/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'reference' => 'HBMNOTIFREF123456',
                    'amount' => 400000, // ₦4,000 in kobo
                    'currency' => 'NGN',
                    'id' => 111,
                    'channel' => 'card',
                ],
            ]),
        ]);

        // The browser callback can be hit twice (refresh / back button).
        $this->actingAs($customer)->get(route('paystack.callback', ['reference' => $payment->reference]));
        $this->actingAs($customer)->get(route('paystack.callback', ['reference' => $payment->reference]));

        // Receipt = in-app + email, sent exactly once for the settlement.
        Notification::assertSentTo($customer, PaymentStatusUpdated::class,
            fn ($notification, $channels) => $channels === ['database', 'mail']);
        Notification::assertSentTimes(PaymentStatusUpdated::class, 1);

        // Verification is what confirms the order (§20): one confirmation
        // email for the customer, never duplicated by the double callback.
        Notification::assertSentTimes(OrderPlaced::class, 1);
    }

    public function test_customer_care_updates_stay_in_app_only(): void
    {
        Notification::fake();
        $customer = $this->customer();
        $admin = $this->staff();

        $this->actingAs($customer)->post(route('support.store'), [
            'category' => 'delivery_issue',
            'subject' => 'Rider never called',
            'description' => 'The rider left the cement at the gate without calling me first.',
        ]);

        $ticket = SupportTicket::firstOrFail();

        $this->actingAs($admin)
            ->patch(route('admin.support.update', $ticket), [
                'status' => 'in_progress',
                'staff_response' => 'Sorry about that — we have spoken to the rider.',
            ])
            ->assertSessionHas('status');

        Notification::assertSentTo($customer, SupportTicketUpdated::class,
            fn ($notification, $channels) => $channels === ['database']);
    }

    public function test_changing_your_password_emails_a_security_alert(): void
    {
        Notification::fake();
        $customer = $this->customer(['password' => 'current-pass-1']);

        $this->actingAs($customer)
            ->post(route('password.change.update'), [
                'current_password' => 'current-pass-1',
                'password' => 'brand-new-pass-1',
                'password_confirmation' => 'brand-new-pass-1',
            ])
            ->assertRedirect(route('profile.edit'));

        Notification::assertSentTo($customer, PasswordChanged::class,
            fn ($notification, $channels) => $channels === ['database', 'mail']);
    }

    public function test_failed_delivery_reaches_the_bell_without_email(): void
    {
        Notification::fake();
        $admin = $this->staff();
        $customer = $this->customer();
        $order = $this->orderFor($customer);

        $delivery = $order->delivery()->create(['status' => 'out_for_delivery']);

        $this->actingAs($admin)
            ->patch(route('admin.deliveries.update', $delivery), [
                'status' => 'failed',
                'failure_reason' => 'customer_unavailable',
                'rescheduled_date' => now()->addDays(3)->toDateString(),
            ])
            ->assertSessionHas('status');

        $this->assertSame('failed', $delivery->fresh()->status);

        // Delivery failed/rescheduled → in-app only, no email.
        Notification::assertSentTo($customer, DeliveryUpdated::class,
            fn ($notification, $channels) => $channels === ['database']);
    }
}
