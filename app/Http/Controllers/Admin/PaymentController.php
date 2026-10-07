<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\ActivityLogger;
use App\Services\PaymentProcessor;
use App\Services\PaymentVerifier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    /** List all payments with search + status/method filters. */
    public function index(Request $request)
    {
        $search = $request->input('search');
        $statusFilter = $request->input('status');
        $methodFilter = $request->input('method');

        $query = Payment::query()->with('order')->latest();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('order', function ($o) use ($search) {
                        $o->where('order_number', 'like', "%{$search}%")
                            ->orWhere('customer_name', 'like', "%{$search}%");
                    });
            });
        }

        if ($statusFilter && array_key_exists($statusFilter, Payment::STATUSES)) {
            $query->where('status', $statusFilter);
        }

        if ($methodFilter && array_key_exists($methodFilter, Payment::METHODS)) {
            $query->where('method', $methodFilter);
        }

        $payments = $query->paginate(15)->withQueryString();
        $statusCounts = Payment::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.payments.index', compact('payments', 'search', 'statusFilter', 'methodFilter', 'statusCounts'));
    }

    /** Update a payment's status (state machine — no invalid jumps). */
    public function updateStatus(Request $request, Payment $payment, PaymentProcessor $processor, ActivityLogger $logger)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Payment::STATUSES))],
        ]);

        $next = $data['status'];

        // A Paystack payment only ever becomes "paid" through verification —
        // staff must never flip it by hand (Phase 8 / Master Prompt §60).
        if ($next === 'paid' && $payment->method === 'paystack') {
            return back()->with(
                'error',
                'A Paystack payment can only be marked paid by verifying it with Paystack. Use the "Verify with Paystack" button.'
            );
        }

        if (! in_array($next, $payment->allowedNextStatuses(), true)) {
            return back()->with('error', 'Cannot change payment "'.$payment->statusLabel().'" to "'.(Payment::STATUSES[$next] ?? $next).'".');
        }

        $previousStatus = $payment->status;

        $payment->status = $next;
        $payment->paid_at = $next === 'paid' ? ($payment->paid_at ?? now()) : $payment->paid_at;
        $payment->save();

        // Keep the order's payment status in step with its payments.
        $processor->recalculateOrder($payment->order);

        // PHASE 12 — audit trail (Master Prompt §47).
        $logger->log('payment.status_changed', $payment, sprintf(
            'Payment %s changed from "%s" to "%s".',
            $payment->reference,
            Payment::STATUSES[$previousStatus] ?? $previousStatus,
            $payment->statusLabel()
        ), [
            'from_status' => $previousStatus,
            'to_status' => $next,
            'method' => $payment->method,
            'amount' => (float) $payment->amount,
        ]);

        return redirect()
            ->back()
            ->with('status', 'Payment '.$payment->reference.' is now "'.$payment->statusLabel().'".');
    }

    /**
     * Ask Paystack whether a pending online payment really happened.
     * This is the staff-side escape hatch for a stuck payment (e.g. the
     * customer's browser never came back) — it still never marks anything
     * paid without Paystack confirming it first.
     */
    public function verifyWithPaystack(Payment $payment, PaymentVerifier $verifier, ActivityLogger $logger)
    {
        if ($payment->method !== 'paystack') {
            return back()->with('error', 'Only online (Paystack) payments can be verified with Paystack.');
        }

        if ($payment->status !== 'pending') {
            return back()->with('error', 'Only a pending Paystack payment can be verified — this one is "'.$payment->statusLabel().'".');
        }

        try {
            $verifier->verifyAndProcess($payment);
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Could not reach Paystack to verify this payment: '.$e->getMessage());
        }

        $fresh = $payment->fresh();

        // PHASE 12 — audit trail (Master Prompt §47).
        $logger->log('payment.verified', $payment, sprintf(
            'Payment %s checked against Paystack — result "%s".',
            $payment->reference,
            $fresh->statusLabel()
        ), [
            'result' => $fresh->status,
            'amount' => (float) $payment->amount,
            'channel' => $fresh->channel,
        ]);

        if ($fresh->status === 'paid') {
            return back()->with('status', 'Paystack confirmed payment '.$payment->reference.' — it is now marked paid.');
        }

        return back()->with(
            'error',
            'Paystack reports payment '.$payment->reference.' as "'.$fresh->statusLabel().'" — it was not marked paid.'
        );
    }
}
