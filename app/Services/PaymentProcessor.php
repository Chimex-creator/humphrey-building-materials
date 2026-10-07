<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\OrderPlaced;
use App\Notifications\PaymentInitiated;
use App\Notifications\PaymentStatusUpdated;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Http\RedirectResponse;

/**
 * Single place where a payment row is created/changes state and where an
 * order's payment status is recomputed — used by checkout, the payment
 * page, the Paystack callback, the Paystack webhook and the admin
 * "Verify with Paystack" button so they can never disagree.
 *
 * Final Spec §18–§20: Paystack only, full total only, and a server-side
 * verified payment AUTOMATICALLY confirms the order (no manual
 * pending → confirmed step anywhere else).
 *
 * IMPORTANT: this class NEVER touches product stock. Stock only changes in
 * CheckoutController when the order is first placed.
 */
class PaymentProcessor
{
    /**
     * Start a Paystack payment for the COMPLETE order total:
     * create the pending payment row → initialize at Paystack → send the
     * browser to Paystack's hosted checkout.
     *
     * Nothing is marked paid here — only verification does that.
     */
    public function startPaystack(Order $order): RedirectResponse
    {
        if (! $order->isPayable()) {
            return redirect()
                ->route('orders.payment', $order)
                ->with('error', 'This order cannot be paid right now.');
        }

        $amount = (float) $order->total;

        if ($amount <= 0) {
            return redirect()
                ->route('orders.payment', $order)
                ->with('error', 'There is nothing to pay on this order.');
        }

        $paystack = app(PaystackService::class);

        if (! $paystack->isConfigured()) {
            return redirect()
                ->route('orders.payment', $order)
                ->with('error', 'Online payments are not configured yet. Please contact the shop.');
        }

        // Unique, unguessable reference shared by us and Paystack.
        $reference = 'HBM'.strtoupper(Str::random(14));

        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => $amount,
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => $reference,
            'notes' => 'Initiated at checkout / payment page',
        ]);

        try {
            $data = $paystack->initialize([
                'amount' => $paystack->toKobo($amount), // Naira → kobo
                'email' => $paystack->safeEmail($order->customer_email),
                'currency' => 'NGN',
                'reference' => $reference,
                'callback_url' => route('paystack.callback'),
                'metadata' => [
                    'order_number' => $order->order_number,
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                ],
            ]);
        } catch (\RuntimeException $e) {
            // Paystack refused to start — record the failure, nothing was taken.
            $payment->status = 'failed';
            $payment->notes = trim($payment->notes."\nInit failed: ".$e->getMessage());
            $payment->save();
            $this->recalculateOrder($order);

            return redirect()
                ->route('orders.payment', $order)
                ->with('error', 'Could not start the payment: '.$e->getMessage());
        }

        // Order payment status becomes "Pending" while the customer is on Paystack.
        $this->recalculateOrder($order);

        $order = $order->fresh(['user']);
        if ($order->user) {
            $order->user->notify(new PaymentInitiated($order, $amount));
        }

        // Off to Paystack's hosted checkout page.
        return redirect()->away($data['authorization_url']);
    }

    /**
     * Mark a payment as successfully paid (idempotent), then — because the
     * full order total is the only thing we ever charge — automatically
     * confirm the order (Final Spec §19/§20).
     *
     * Returns true only the FIRST time it runs for a payment — so a callback
     * + webhook double-hit can never create duplicate payments, duplicate
     * notifications or double processing.
     */
    public function markPaid(Payment $payment, array $meta = []): bool
    {
        if ($payment->status === 'paid') {
            return false; // already processed — do nothing
        }

        $payment->status = 'paid';
        $payment->paid_at = $payment->paid_at ?? now();

        if (! empty($meta['transaction_id'])) {
            $payment->transaction_id = $meta['transaction_id'];
        }
        if (! empty($meta['channel'])) {
            $payment->channel = $meta['channel'];
        }

        $payment->save();

        $order = $payment->order()->with('user')->first();
        $this->recalculateOrder($order);

        // In-app + email receipt for the customer (money in).
        if ($order && $order->user) {
            $order->user->notify(new PaymentStatusUpdated(
                'Order Fully Paid',
                'We have received the payment of ₦'.number_format((float) $payment->amount, 0)
                    .' for order '.$order->order_number.'.',
                $order,
                true // successful payment → in-app + email receipt
            ));
        }

        Log::info('Payment paid', [
            'payment_id' => $payment->id,
            'reference' => $payment->reference,
            'order' => $order?->order_number,
        ]);

        // PHASE 12 — audit trail (Master Prompt §47). user_id stays null when
        // this arrives from Paystack's webhook, where nobody is logged in.
        app(ActivityLogger::class)->log('payment.paid', $payment, sprintf(
            'Payment of ₦%s received for order %s (reference %s).',
            number_format((float) $payment->amount, 0),
            $order?->order_number ?? '#'.$payment->order_id,
            $payment->reference
        ), [
            'amount' => (float) $payment->amount,
            'reference' => $payment->reference,
            'channel' => $payment->channel,
        ]);

        // Final Spec §19/§20 — verified payment automatically confirms.
        if ($order && $order->payment_status === 'paid') {
            $this->confirmOrder($order);
        }

        return true;
    }

    /**
     * Payment verified → order confirmed (Final Spec §20). Idempotent: only
     * an order still waiting for payment moves, and it moves exactly once.
     */
    protected function confirmOrder(Order $order): void
    {
        $order->refresh();

        if ($order->status !== 'pending') {
            return;
        }

        $order->status = 'confirmed';
        $order->confirmed_at = $order->confirmed_at ?? now();
        $order->confirmed_by = null; // system action — payment verification
        $order->save();

        $order->statusHistory()->create([
            'from_status' => 'pending',
            'to_status' => 'confirmed',
            'changed_by' => null,
            'notes' => 'Payment verified — order confirmed automatically',
        ]);

        // Order confirmation: in-app + email for the customer...
        if ($order->user) {
            $order->user->notify(new OrderPlaced($order));
        }

        // ...and an in-app "new order" alert for the staff who handle orders.
        User::whereIn('role', ['admin', 'sales'])
            ->where('is_active', true)
            ->when($order->user_id, fn ($q) => $q->where('id', '!=', $order->user_id))
            ->get()
            ->each(fn (User $staff) => $staff->notify(new OrderPlaced($order, true)));

        app(ActivityLogger::class)->log('order.confirmed', $order, sprintf(
            'Order %s confirmed automatically after payment verification.',
            $order->order_number
        ));

        Log::info('Order confirmed by payment', ['order' => $order->order_number]);
    }

    /** Mark a payment as failed (idempotent). */
    public function markFailed(Payment $payment, ?string $reason = null): bool
    {
        if (in_array($payment->status, ['paid', 'failed'], true)) {
            return false;
        }

        $payment->status = 'failed';
        if ($reason) {
            $payment->notes = trim(($payment->notes ? $payment->notes."\n" : '').'Failed: '.$reason);
        }
        $payment->save();

        $order = $payment->order()->with('user')->first();
        $this->recalculateOrder($order);

        if ($order && $order->user) {
            $order->user->notify(new PaymentStatusUpdated(
                'Payment Failed',
                'Your payment for order '.$order->order_number.' could not be completed'.($reason ? ': '.$reason : '.').' You can try again from your order page.',
                $order
            ));
        }

        return true;
    }

    /**
     * Recompute an order's payment_status from its payment rows.
     *
     * unpaid   → nothing successful, nothing in flight
     * pending  → a Paystack payment is waiting for confirmation
     * paid     → successful payments cover the full total
     * failed   → nothing successful and at least one failed attempt
     *
     * There is deliberately NO "partially paid" and NO "refunded": every
     * order settles in full through Paystack (Final Spec §18/§27).
     */
    public function recalculateOrder(?Order $order): void
    {
        if (! $order) {
            return;
        }

        $payments = $order->payments()->get();

        $paid = 0.0;
        $hasPending = false;
        $hasFailed = false;

        foreach ($payments as $p) {
            if ($p->status === 'paid') {
                $paid += (float) $p->amount;
            } elseif ($p->status === 'pending') {
                $hasPending = true;
            } elseif ($p->status === 'failed') {
                $hasFailed = true;
            }
        }

        $total = (float) $order->total;

        if ($total > 0 && $paid >= $total) {
            $status = 'paid';
        } elseif ($hasPending) {
            $status = 'pending';
        } elseif ($hasFailed) {
            $status = 'failed';
        } else {
            $status = 'unpaid';
        }

        if ($order->payment_status !== $status) {
            $order->payment_status = $status;
            $order->save();
        }
    }

    /** Successful payments received for an order. */
    public function paidAmount(Order $order): float
    {
        return (float) $order->payments()
            ->where('status', 'paid')
            ->sum('amount');
    }
}
