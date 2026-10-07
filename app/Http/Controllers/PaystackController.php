<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentProcessor;
use App\Services\PaymentVerifier;
use App\Services\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * PHASE 8 — where Paystack tells us the truth about a payment.
 *
 * GET  /paystack/callback → customer's browser comes back from Paystack
 * POST /paystack/webhook  → Paystack's servers call us directly
 *
 * BOTH paths verify the transaction with Paystack's API and then run the
 * exact same idempotent processing, so a double-hit can never double-record.
 */
class PaystackController extends Controller
{
    /** Browser return from Paystack (?reference=HBM...). */
    public function callback(Request $request)
    {
        $reference = (string) $request->query('reference', '');

        if ($reference === '') {
            return redirect()->route('home')->with('error', 'Payment reference missing.');
        }

        $payment = Payment::where('reference', $reference)->first();

        if (! $payment) {
            return redirect()->route('home')->with('error', 'Payment reference not found.');
        }

        $order = $payment->order;

        try {
            $this->verify($payment);
        } catch (\RuntimeException $e) {
            // Could not reach/confirm with Paystack — leave the payment PENDING
            // (never mark paid, never assume failure on a network problem).
            Log::warning('Paystack callback could not verify', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return $this->redirectToOrder($order, 'error', 'We could not confirm your payment yet. It will be confirmed automatically — please check again shortly.');
        }

        $fresh = $payment->fresh();

        // The order was loaded BEFORE verification — refresh it so the
        // redirect decision sees the freshly paid state (§19/§20).
        $order?->refresh();

        if ($fresh->status === 'paid') {
            return $this->redirectToOrder($order, 'status', 'Payment confirmed for order '.$order->order_number.'. Thank you!');
        }

        return $this->redirectToOrder($order, 'error', 'Your payment was not completed. You can try again from this page.');
    }

    /** Server-to-server notifications from Paystack. */
    public function webhook(Request $request, PaystackService $paystack)
    {
        $raw = $request->getContent();

        // 1) Signature check — only Paystack can produce this HMAC.
        if (! $paystack->validSignature($request->header('X-Paystack-Signature'), $raw)) {
            return response()->json(['status' => false, 'message' => 'Invalid signature'], 401);
        }

        $event = json_decode($raw, true);
        $type = $event['event'] ?? '';
        $data = $event['data'] ?? [];
        $reference = (string) ($data['reference'] ?? '');

        // Always acknowledge unknown/irrelevant events so Paystack stops retrying.
        if ($reference === '') {
            return response()->json(['status' => true]);
        }

        $payment = Payment::where('reference', $reference)->first();

        if (! $payment) {
            Log::info('Webhook for unknown reference', ['reference' => $reference]);

            return response()->json(['status' => true]);
        }

        if ($type === 'charge.success') {
            try {
                $this->verify($payment);
            } catch (\RuntimeException $e) {
                Log::warning('Webhook could not verify', ['reference' => $reference, 'error' => $e->getMessage()]);
                // 200 on purpose: we received it fine; we will re-verify later.
            }
        } elseif ($type === 'charge.failed') {
            app(PaymentProcessor::class)->markFailed($payment, 'Paystack reported the charge failed.');
        }

        return response()->json(['status' => true]);
    }

    /* ------------------------------------------------------------
     | Shared verification (the ONLY place a payment becomes "paid")
     * ------------------------------------------------------------ */

    /**
     * The real work lives in PaymentVerifier so the browser callback, the
     * webhook and the staff "Verify with Paystack" button can never drift
     * apart. See app/Services/PaymentVerifier.php.
     *
     * @throws RuntimeException when Paystack cannot confirm the transaction.
     */
    protected function verify(Payment $payment): void
    {
        app(PaymentVerifier::class)->verifyAndProcess($payment);
    }

    /** Send the browser to the right page after verification was attempted. */
    protected function redirectToOrder(?Order $order, string $key, string $message)
    {
        if ($order) {
            $user = auth()->user();

            // Staff watching a customer's payment land on the staff screen.
            if ($user && $user->isStaff()) {
                return redirect()->route('orders.show', $order)->with($key, $message);
            }

            // Payment verified → the order is confirmed: show the
            // confirmation page (Final Spec §19/§20).
            if ($order->isPaid()) {
                return redirect()->route('checkout.success', $order->id)->with($key, $message);
            }

            // Not verified yet → back to the payment page so the customer
            // can retry; coming back from Paystack alone confirms nothing.
            return redirect()->route('orders.payment', $order)->with($key, $message);
        }

        return redirect()->route('home')->with($key, $message);
    }
}
