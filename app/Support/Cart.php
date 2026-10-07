<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Session-based shopping cart.
 *
 * The session only stores [product_id => qty].
 * Product details are always loaded fresh from the DB so prices/stock stay accurate.
 */
class Cart
{
    protected const SESSION_KEY = 'cart';

    /** Raw map of product_id => quantity from the session. */
    protected static function raw(): array
    {
        return session(self::SESSION_KEY, []);
    }

    protected static function putRaw(array $items): void
    {
        session([self::SESSION_KEY => $items]);
    }

    /**
     * Cart lines hydrated from the DB (missing/deactivated products dropped).
     *
     * @return Collection<int, object{id:int, product:Product, qty:int, line_total:float}>
     */
    public static function items(): Collection
    {
        $raw = self::raw();
        if ($raw === []) {
            return collect();
        }

        $products = Product::with('category')
            ->whereIn('id', array_keys($raw))
            ->where('status', 'active')
            ->get()
            ->keyBy('id');

        $lines = collect();
        $changed = false;

        foreach ($raw as $productId => $qty) {
            $product = $products->get((int) $productId);

            // Product deleted or deactivated → drop from cart.
            if (! $product) {
                $changed = true;

                continue;
            }

            $qty = max(1, (int) $qty);

            // Clamp qty to available stock (stock may have dropped since add).
            if ($qty > $product->stock_quantity) {
                $qty = (int) $product->stock_quantity;
                $changed = true;
            }

            if ($qty < 1) {
                $changed = true;

                continue;
            }

            $lines->push((object) [
                'id' => $product->id,
                'product' => $product,
                'qty' => $qty,
                'line_total' => $qty * (float) $product->price,
            ]);
        }

        // Persist any auto-fixes (clamped qty / dropped lines).
        if ($changed) {
            self::putRaw($lines->mapWithKeys(fn ($l) => [$l->id => $l->qty])->all());
        }

        return $lines;
    }

    /** Total number of units in the cart (for the header badge). */
    public static function count(): int
    {
        return (int) self::items()->sum('qty');
    }

    public static function isEmpty(): bool
    {
        return self::items()->isEmpty();
    }

    public static function subtotal(): float
    {
        return (float) self::items()->sum('line_total');
    }

    /**
     * Add a product (or increase quantity). Returns false if out of stock.
     */
    public static function add(Product $product, int $qty = 1): bool
    {
        if (! $product->isActive() || $product->stock_quantity < 1) {
            return false;
        }

        $qty = max(1, $qty);
        $raw = self::raw();
        $current = (int) ($raw[$product->id] ?? 0);
        $newQty = $current + $qty;

        if ($newQty > $product->stock_quantity) {
            // Cap at available stock instead of failing silently below.
            if ($product->stock_quantity <= $current) {
                return false;
            }
            $newQty = $product->stock_quantity;
        }

        $raw[$product->id] = $newQty;
        self::putRaw($raw);

        return true;
    }

    /** Set absolute quantity for a line. Qty 0 removes the line. */
    public static function update(int $productId, int $qty): bool
    {
        $raw = self::raw();
        if (! array_key_exists($productId, $raw)) {
            return false;
        }

        if ($qty < 1) {
            unset($raw[$productId]);
            self::putRaw($raw);

            return true;
        }

        $product = Product::find($productId);
        if (! $product || ! $product->isActive()) {
            unset($raw[$productId]);
            self::putRaw($raw);

            return false;
        }

        $qty = min($qty, $product->stock_quantity);
        if ($qty < 1) {
            unset($raw[$productId]);
            self::putRaw($raw);

            return true;
        }

        $raw[$productId] = $qty;
        self::putRaw($raw);

        return true;
    }

    public static function remove(int $productId): void
    {
        $raw = self::raw();
        unset($raw[$productId]);
        self::putRaw($raw);
    }

    public static function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
