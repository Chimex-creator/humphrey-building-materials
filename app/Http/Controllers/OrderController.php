<?php

namespace App\Http\Controllers;

use App\Models\Order;

class OrderController extends Controller
{
    /** "My Orders" — only the logged-in customer's own orders. */
    public function index()
    {
        $orders = Order::where('user_id', auth()->id())
            ->withCount('items')
            ->latest()
            ->paginate(10);

        return view('orders.index', compact('orders'));
    }

    /** Order detail — owner or staff only. */
    public function show(Order $order)
    {
        $user = auth()->user();

        // Guests cannot reach this route (auth middleware). Customers see only their own.
        if (! $user->isStaff() && (int) $order->user_id !== (int) $user->id) {
            abort(403, 'This order does not belong to you.');
        }

        $order->load('items', 'payment', 'returns.product');

        return view('orders.show', compact('order'));
    }
}
