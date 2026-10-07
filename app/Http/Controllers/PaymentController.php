<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\PaymentProcessor;

/**
 * Customer payment page + Paystack "initialize" action (Final Spec §18).
 *
 * Checkout already sends the browser straight to Paystack; this page is the
 * retry path when an initialization failed or verification could not be
 * confirmed yet. Starting a payment is NOT proof of payment — only the
 * server-side verification (callback/webhook/staff verify) marks it paid.
 */
class PaymentController extends Controller
{
    /** Payment page: order summary + Pay Online with Paystack. */
    public function show(Order $order)
    {
        $this->authorizeOwner($order);

        $order->load('items', 'payments');

        return view('orders.payment', compact('order'));
    }

    /**
     * "Pay Online" — the COMPLETE order total through Paystack.
     *
     * For a delivery order the total already includes the confirmed delivery
     * fee, so the customer never pays the goods now and the fee later.
     */
    public function payWithPaystack(Order $order)
    {
        $this->authorizePayer($order);

        return app(PaymentProcessor::class)->startPaystack($order);
    }

    /* ------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------ */

    /** Owner or staff may VIEW the page. */
    protected function authorizeOwner(Order $order): void
    {
        $user = auth()->user();

        if (! $user->isStaff() && (int) $order->user_id !== (int) $user->id) {
            abort(403, 'This order does not belong to you.');
        }
    }

    /** Only the order's owner may start a payment. */
    protected function authorizePayer(Order $order): void
    {
        if ((int) $order->user_id !== (int) auth()->id()) {
            abort(403, 'This order does not belong to you.');
        }
    }
}
