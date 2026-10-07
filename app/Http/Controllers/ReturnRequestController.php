<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\ProductReturn;
use App\Models\User;
use App\Notifications\ReturnRequested;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PHASE 10 — customer-initiated return requests.
 *
 * A customer can only ask; nothing moves until staff approve it. Stock and
 * money are touched exclusively by the admin side of the same workflow.
 */
class ReturnRequestController extends Controller
{
    /** The "request a return" form for one of the customer's own orders. */
    public function create(Order $order)
    {
        $this->authorizeOrder($order);

        if ($order->status !== 'delivered') {
            return redirect()
                ->route('orders.show', $order)
                ->with('error', 'Only delivered orders can have items returned. This order is "'
                    .$order->statusLabel().'".');
        }

        $lines = $this->returnableLines($order);

        if ($lines === []) {
            return redirect()
                ->route('orders.show', $order)
                ->with('error', 'There is nothing left on this order to return.');
        }

        return view('orders.return', compact('order', 'lines'));
    }

    /** Save the request and put it in front of the staff for review. */
    public function store(Request $request, Order $order)
    {
        $this->authorizeOrder($order);

        if ($order->status !== 'delivered') {
            return redirect()
                ->route('orders.show', $order)
                ->with('error', 'Only delivered orders can have items returned.');
        }

        $productIds = $order->items->pluck('product_id')->filter()->unique()->values()->all();

        if (empty($productIds)) {
            return redirect()
                ->route('orders.show', $order)
                ->with('error', 'This order has no returnable items.');
        }

        $data = $request->validate([
            'product_id' => ['required', 'integer', Rule::in($productIds)],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000000'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'product_id.in' => 'That item is not part of this order.',
            'reason.min' => 'Please tell us why you are returning the item (at least 3 characters).',
        ]);

        $productId = (int) $data['product_id'];
        $requested = (int) $data['quantity'];
        $line = $order->items->firstWhere('product_id', $productId);

        if (! $line) {
            return redirect()
                ->route('orders.show', $order)
                ->with('error', 'That item is not part of this order.');
        }

        $alreadyReturned = $this->returnedQuantity($order, $productId);
        $remaining = (int) $line->quantity - $alreadyReturned;

        if ($requested > $remaining) {
            return redirect()
                ->route('orders.show', $order)->withInput()->withErrors([
                    'quantity' => $remaining <= 0
                        ? 'You have already requested a return for all of that item.'
                        : 'You can request at most '.$remaining.' more of "'.$line->product_name
                            .'" ('.$line->quantity.' ordered, '.$alreadyReturned.' already requested).',
                ]);
        }

        $return = ProductReturn::create([
            'product_id' => $productId,
            'order_id' => $order->id,
            'quantity' => $requested,
            'reason' => $data['reason'],
            'source' => 'customer',
            'status' => 'pending',
            'returned_by' => auth()->id(),
        ]);

        // In-app alert for the staff who handle stock — no email flood.
        User::whereIn('role', ['admin', 'inventory'])
            ->where('is_active', true)
            ->get()
            ->each(fn (User $staff) => $staff->notify(new ReturnRequested($return)));

        return redirect()
            ->route('orders.show', $order)
            ->with('status', 'Return request sent for '.$return->quantity.' × '.$line->product_name
                .'. We will review it and notify you.');
    }

    /* ------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------ */

    /** Customers see only their own orders; staff may open any of them. */
    private function authorizeOrder(Order $order): void
    {
        $user = auth()->user();

        if (! $user->isStaff() && (int) $order->user_id !== (int) $user->id) {
            abort(403, 'This order does not belong to you.');
        }
    }

    /**
     * Order lines that still have un-returned quantity, each with how much
     * the customer may still ask for. Plain arrays so nothing is written
     * onto the OrderItem models.
     */
    private function returnableLines(Order $order): array
    {
        $lines = [];

        foreach ($order->items as $item) {
            if (! $item->product_id) {
                continue;
            }

            $returned = $this->returnedQuantity($order, (int) $item->product_id);
            $remaining = (int) $item->quantity - $returned;

            if ($remaining <= 0) {
                continue;
            }

            $lines[] = [
                'product_id' => (int) $item->product_id,
                'name' => $item->product_name.($item->unit ? ' / '.$item->unit : ''),
                'unit_price' => (float) $item->unit_price,
                'ordered' => (int) $item->quantity,
                'returned' => $returned,
                'remaining' => $remaining,
            ];
        }

        return $lines;
    }

    /** Units already requested or accepted for this product on this order. */
    private function returnedQuantity(Order $order, int $productId): int
    {
        return (int) $order->returns()
            ->where('product_id', $productId)
            ->where('status', '!=', 'declined')
            ->sum('quantity');
    }
}
