<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Payments: real Paystack flow (server-created reference, hosted checkout,
 * server-side verification before anything is marked paid) — Paystack is the
 * only way money moves (Final Spec §18); there are no manual methods left.
 */
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_test_fake_secret_key';

    private const BASE = 'https://api.paystack.co';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.key' => self::SECRET,
            'services.paystack.public_key' => 'pk_test_fake_public_key',
            'services.paystack.url' => self::BASE,
        ]);
    }

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
            'delivery_option' => 'pickup',
            'payment_option' => 'paystack',
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'subtotal' => 4000,
            'delivery_fee' => 0,
            'total' => 4000,
        ], $attrs));
    }

    private function paystackPayment(Order $order, string $status = 'pending'): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'amount' => (float) $order->total,
            'method' => 'paystack',
            'status' => $status,
            'reference' => 'HBMTESTREF123456',
            'notes' => 'Initiated by customer on payment page',
        ]);
    }

    private function fakeVerify(array $overrides = []): void
    {
        Http::fake([
            self::BASE.'/*' => Http::response([
                'status' => true,
                'data' => array_merge([
                    'status' => 'success',
                    'reference' => 'HBMTESTREF123456',
                    'amount' => 400000, // ₦4,000 in kobo
                    'currency' => 'NGN',
                    'id' => 987654,
                    'channel' => 'card',
                ], $overrides),
            ]),
        ]);
    }

    /* ------------------------------------------------------------------
     | 1. The customer page offers REAL Paystack, never a demo card
     * ---------------------------------------------------------------- */

    public function test_payment_page_offers_paystack_and_never_a_demo_card(): void
    {
        $customer = $this->customer();
        $order = $this->orderFor($customer);

        $response = $this->actingAs($customer)->get(route('orders.payment', $order));

        $response->assertOk();
        $response->assertSee('Paystack');
        $response->assertSee('₦4,000');
        $response->assertSee('Pay ₦4,000 Online (Paystack)');
        $response->assertDontSee('Card (Demo)');
        $response->assertDontSee('demo card', false);
        $response->assertSee("Paystack's secure checkout", false);
    }

    public function test_online_payment_cannot_start_until_the_delivery_fee_is_confirmed(): void
    {
        $customer = $this->customer();
        $order = $this->orderFor($customer, [
            'delivery_option' => 'delivery',
            'delivery_fee_status' => 'pending_confirmation',
            'delivery_address' => '12 Ogui Road, Enugu',
        ]);

        $response = $this->actingAs($customer)
            ->from(route('orders.payment', $order))
            ->post(route('orders.payment.paystack', $order));

        $response->assertSessionHas('error');
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame(0, Payment::count());
    }

    /* ------------------------------------------------------------------
     | 2. Staff can NEVER mark a Paystack payment paid by hand
     * ---------------------------------------------------------------- */

    public function test_staff_cannot_mark_a_paystack_payment_paid_by_hand(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $order = $this->orderFor($this->customer());
        $payment = $this->paystackPayment($order);

        $response = $this->actingAs($admin)
            ->patch(route('admin.payments.status', $payment), ['status' => 'paid']);

        $response->assertSessionHas('error');
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_staff_can_still_fail_a_paystack_payment_by_hand(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $order = $this->orderFor($this->customer());
        $payment = $this->paystackPayment($order);

        $this->actingAs($admin)
            ->from(route('admin.payments.index'))
            ->patch(route('admin.payments.status', $payment), ['status' => 'failed'])
            ->assertSessionHas('status');

        $this->assertSame('failed', $payment->fresh()->status);
    }

    public function test_manual_payments_can_still_be_set_to_paid_by_staff(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $order = $this->orderFor($this->customer(), ['payment_option' => 'pay_on_delivery']);
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => 4000,
            'method' => 'manual',
            'status' => 'unpaid',
            'reference' => 'RCPT-1-MANUAL01',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.payments.index'))
            ->patch(route('admin.payments.status', $payment), ['status' => 'paid'])
            ->assertSessionHas('status');

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    /* ------------------------------------------------------------------
     | 3. "Verify with Paystack" — the ONLY way a Paystack row gets paid
     * ---------------------------------------------------------------- */

    public function test_verification_marks_the_payment_paid_only_when_paystack_confirms(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $order = $this->orderFor($this->customer());
        $payment = $this->paystackPayment($order);

        $this->fakeVerify();

        $this->actingAs($admin)
            ->from(route('admin.payments.index'))
            ->patch(route('admin.payments.verify', $payment))
            ->assertSessionHas('status');

        $fresh = $payment->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertNotNull($fresh->paid_at);
        $this->assertSame(987654, (int) $fresh->transaction_id);
        $this->assertSame('card', $fresh->channel);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_verification_leaves_the_payment_alone_when_paystack_says_it_failed(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $order = $this->orderFor($this->customer());
        $payment = $this->paystackPayment($order);

        Log::spy();
        $this->fakeVerify(['status' => 'abandoned']);

        $this->actingAs($admin)
            ->from(route('admin.payments.index'))
            ->patch(route('admin.payments.verify', $payment))
            ->assertSessionHas('error');

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertSame('failed', $order->fresh()->payment_status);
    }

    public function test_verification_rejects_an_amount_mismatch(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $order = $this->orderFor($this->customer());
        $payment = $this->paystackPayment($order);

        Log::spy();
        $this->fakeVerify(['amount' => 100]); // customer underpaid

        $this->actingAs($admin)
            ->from(route('admin.payments.index'))
            ->patch(route('admin.payments.verify', $payment))
            ->assertSessionHas('error');

        $this->assertSame('failed', $payment->fresh()->status);
    }

    public function test_verification_refuses_non_paystack_and_non_pending_rows(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $order = $this->orderFor($this->customer(), ['payment_option' => 'pay_on_delivery']);
        $manual = Payment::create([
            'order_id' => $order->id,
            'amount' => 4000,
            'method' => 'manual',
            'status' => 'unpaid',
            'reference' => 'RCPT-1-MANUAL02',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.payments.verify', $manual))
            ->assertSessionHas('error');

        $paid = Payment::create([
            'order_id' => $order->id,
            'amount' => 1000,
            'method' => 'paystack',
            'status' => 'paid',
            'reference' => 'HBMPAIDREF123456',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.payments.verify', $paid))
            ->assertSessionHas('error');

        $this->assertSame('unpaid', $manual->fresh()->status);
        $this->assertSame('paid', $paid->fresh()->status);
    }

    /* ------------------------------------------------------------------
     | 4. The browser callback and the webhook both verify server-side
     * ---------------------------------------------------------------- */

    public function test_callback_verifies_with_paystack_before_marking_paid(): void
    {
        $customer = $this->customer();
        $order = $this->orderFor($customer);
        $payment = $this->paystackPayment($order);

        $this->fakeVerify();

        $response = $this->actingAs($customer)
            ->get(route('paystack.callback', ['reference' => $payment->reference]));

        // Verified → the order is confirmed: straight to the success page (§19/§20).
        $response->assertRedirect(route('checkout.success', $order->id));
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_webhook_requires_a_valid_signature(): void
    {
        $order = $this->orderFor($this->customer());
        $payment = $this->paystackPayment($order);

        $payload = json_encode([
            'event' => 'charge.success',
            'data' => ['reference' => $payment->reference],
        ]);

        $this->call('POST', route('paystack.webhook'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => 'not-a-real-signature',
        ], $payload)->assertStatus(401);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_webhook_with_a_good_signature_verifies_and_marks_paid(): void
    {
        $order = $this->orderFor($this->customer());
        $payment = $this->paystackPayment($order);

        $this->fakeVerify();

        $payload = json_encode([
            'event' => 'charge.success',
            'data' => ['reference' => $payment->reference],
        ]);
        $signature = hash_hmac('sha512', $payload, self::SECRET);

        $this->call('POST', route('paystack.webhook'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $payload)->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_a_webhook_for_a_failed_charge_marks_the_payment_failed(): void
    {
        $order = $this->orderFor($this->customer());
        $payment = $this->paystackPayment($order);

        $payload = json_encode([
            'event' => 'charge.failed',
            'data' => ['reference' => $payment->reference],
        ]);
        $signature = hash_hmac('sha512', $payload, self::SECRET);

        $this->call('POST', route('paystack.webhook'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $payload)->assertOk();

        $this->assertSame('failed', $payment->fresh()->status);
    }

    /* ------------------------------------------------------------------
     | 5. Manual payment recording no longer exists (Final Spec §18)
     * ---------------------------------------------------------------- */

    public function test_recording_a_payment_by_hand_is_gone(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $order = $this->orderFor($this->customer(), [
            'delivery_option' => 'delivery',
            'delivery_fee_status' => 'pending_confirmation',
        ]);

        // The old staff "record payment" endpoint does not exist anymore —
        // online payment still waits for the fee to be confirmed (§14/§16).
        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order))
            ->post('/admin/orders/'.$order->id.'/record-payment', [
                'amount' => 4000,
                'method' => 'pay_on_delivery',
            ])
            ->assertNotFound();

        $this->assertSame(0, Payment::count());
    }

    /* ------------------------------------------------------------------
     | 6. Partial payments do not exist (Master Scope §6)
     * ---------------------------------------------------------------- */

    public function test_partial_payment_route_does_not_exist(): void
    {
        $customer = $this->customer();
        $order = $this->orderFor($customer, ['payment_option' => 'pay_on_delivery']);

        $this->actingAs($customer)
            ->from(route('orders.payment', $order))
            ->post('/orders/'.$order->id.'/payment/partial', ['amount' => 1000])
            ->assertNotFound();

        $this->assertSame(0, Payment::count());
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }
}
