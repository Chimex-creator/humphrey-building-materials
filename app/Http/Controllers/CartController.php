<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\Cart;
use Illuminate\Http\Request;

class CartController extends Controller
{
    /** Shopping cart page. */
    public function index()
    {
        $items = Cart::items();
        $subtotal = Cart::subtotal();

        return view('cart.index', compact('items', 'subtotal'));
    }

    /**
     * Add a product to the cart.
     *
     * Two flavours of the same action:
     *  - normal form POST → redirect back (works with JavaScript disabled)
     *  - AJAX/fetch POST  → JSON so the catalogue can show a toast and update
     *                       the header counter WITHOUT reloading the page
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'qty' => ['required', 'integer', 'min:1', 'max:9999'],
        ]);

        $product = Product::findOrFail($data['product_id']);

        if (! $product->isActive()) {
            return $this->addedResponse($request, false, 'That product is not available.', $product);
        }

        // Minimum order quantity, quantity step and stock all in one rule (§15).
        if ($error = $product->quantityError((int) $data['qty'])) {
            return $this->addedResponse($request, false, $error, $product);
        }

        if (! Cart::add($product, (int) $data['qty'])) {
            return $this->addedResponse($request, false, 'Sorry — not enough stock for "'.$product->name.'".', $product);
        }

        $message = $data['qty'].' × '.$product->name.' added to your cart.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'cart_count' => Cart::count(),
                'cart_url' => route('cart.index'),
            ]);
        }

        return redirect()->route('cart.index')->with('status', $message);
    }

    /** Shared error/success payload for the AJAX and normal POST paths. */
    private function addedResponse(Request $request, bool $ok, string $message, Product $product)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => $ok,
                'message' => $message,
                'cart_count' => Cart::count(),
                'product_url' => route('products.show', $product->slug),
            ], $ok ? 200 : 422);
        }

        return back()->with($ok ? 'status' : 'error', $message);
    }

    /** Update quantity for one cart line. */
    public function update(Request $request, int $productId)
    {
        $data = $request->validate([
            'qty' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        $product = Product::find($productId);

        // Qty 0 means "remove this line" and is always allowed.
        if ($product && (int) $data['qty'] > 0) {
            if ($error = $product->quantityError((int) $data['qty'])) {
                return back()->with('error', $error);
            }
        }

        Cart::update($productId, (int) $data['qty']);

        return redirect()->route('cart.index')->with('status', 'Cart updated.');
    }

    /** Remove one line from the cart. */
    public function destroy(int $productId)
    {
        Cart::remove($productId);

        return redirect()->route('cart.index')->with('status', 'Item removed from cart.');
    }

    /** Empty the whole cart. */
    public function clear()
    {
        Cart::clear();

        return redirect()->route('cart.index')->with('status', 'Your cart is now empty.');
    }
}
