<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PHASE 8 — the ONLY place a Paystack payment becomes "paid".
 *
 * Used by the browser callback, Paystack's webhook AND the staff
 * "Verify with Paystack" button, so all three paths apply exactly the
 * same checks and can never disagree.
 */
class PaymentVerifier
{
    /**
     * Ask Paystack directly, then apply our checks:
     *   0. the payment still belongs to a live, uncancelled order
     *   1. reference matches
     *   2. amount matches (in kobo — server-side, from OUR database)
     *   3. our amount equals the order total (Master Scope §6: the order is
     *      paid in full or not at all — no partial settlements)
     *   4. currency is NGN
     *   5. transaction status is success
     * Only then is the payment marked paid (idempotently).
     *
     * @throws RuntimeException when Paystack cannot confirm the transaction.
     */
    public function verifyAndProcess(Payment $payment): void
    {
        $paystack = app(PaystackService::class);
        $processor = app(PaymentProcessor::class);

        // Already settled (e.g. webhook arrived before callback) — do nothing.
        if ($payment->status === 'paid') {
            return;
        }

        // 0) The payment must still sit on a real, cancellable order.
        $order = $payment->order;

        if ($order === null) {
            Log::error('Paystack payment with no order', ['reference' => $payment->reference]);
            $processor->markFailed($payment, 'Payment has no order — payment rejected.');

            return;
        }

        if ($order->status === 'cancelled') {
            $processor->markFailed($payment, 'Order is cancelled — payment rejected.');

            return;
        }

        // 3) Our recorded amount must be the whole order total. Partial
        //    payments are out of scope, so anything else is a bug or a
        //    tampered row and never reaches markPaid().
        if (round((float) $payment->amount, 2) !== round((float) $order->total, 2)) {
            Log::error('Payment amount is not the order total', [
                'reference' => $payment->reference,
                'payment_amount' => $payment->amount,
                'order_total' => $order->total,
            ]);
            $processor->markFailed($payment, 'Payment does not match the order total — payment rejected.');

            return;
        }

        $data = $paystack->verify($payment->reference);

        $apiStatus = (string) ($data['status'] ?? '');
        $apiRef = (string) ($data['reference'] ?? '');
        $apiAmount = (int) ($data['amount'] ?? -1);   // kobo
        $apiCurrency = (string) ($data['currency'] ?? '');

        // 1) Same reference?
        if ($apiRef !== $payment->reference) {
            Log::error('Paystack reference mismatch', [
                'expected' => $payment->reference,
                'got' => $apiRef,
            ]);
            $processor->markFailed($payment, 'Reference mismatch — payment rejected.');

            return;
        }

        // 2) Same amount? (Paystack sends kobo)
        $expected = $paystack->toKobo((float) $payment->amount);
        if ($apiAmount !== $expected) {
            Log::error('Paystack amount mismatch', [
                'expected_kobo' => $expected,
                'got_kobo' => $apiAmount,
                'reference' => $payment->reference,
            ]);
            $processor->markFailed($payment, 'Amount mismatch — payment rejected.');

            return;
        }

        // 3) Naira only.
        if ($apiCurrency !== 'NGN') {
            $processor->markFailed($payment, 'Wrong currency — payment rejected.');

            return;
        }

        // 4) Paystack says success?
        if ($apiStatus === 'success') {
            $processor->markPaid($payment, [
                'transaction_id' => $data['id'] ?? null,
                'channel' => $data['channel'] ?? null,
            ]);
        } else {
            $processor->markFailed($payment, 'Paystack status: '.$apiStatus);
        }
    }
}
