<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /** List products with search + filters. */
    public function index(Request $request)
    {
        $search = $request->input('search');
        $categoryFilter = $request->input('category');
        $statusFilter = $request->input('status');

        $query = Product::with('category')->latest();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($categoryFilter && is_numeric($categoryFilter)) {
            $query->where('category_id', (int) $categoryFilter);
        }

        if (in_array($statusFilter, ['active', 'inactive'], true)) {
            $query->where('status', $statusFilter);
        }

        $products = $query->paginate(15)->withQueryString();
        $categories = Category::orderBy('name')->get();

        // Configurable low-stock threshold (§35) — shared with the
        // dashboard and the inventory screen so they can never disagree.
        $lowStockThreshold = Setting::int('low_stock_threshold', 10);

        return view('admin.products.index', compact('products', 'categories', 'search', 'categoryFilter', 'statusFilter', 'lowStockThreshold'));
    }

    /**
     * Final Spec §39 — bulk activate / deactivate products.
     *
     * Non-destructive by design: products are only hidden or shown in the
     * shop, never deleted, and every run is written to the audit trail.
     */
    public function bulk(Request $request, ActivityLogger $logger)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['activate', 'deactivate'])],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ]);

        $status = $data['action'] === 'activate' ? 'active' : 'inactive';

        $changed = Product::whereIn('id', $data['product_ids'])
            ->where('status', '!=', $status)
            ->update(['status' => $status]);

        if ($changed > 0) {
            $logger->log('products.bulk_'.$data['action'], null, sprintf(
                '%d product(s) %s in one bulk action.',
                $changed,
                $status
            ), [
                'count' => $changed,
                'ids' => array_map('intval', $data['product_ids']),
            ]);
        }

        return back()->with('status', $changed > 0
            ? $changed.' product(s) marked as '.$status.'.'
            : 'Nothing to change — the selected products were already '.$status.'.');
    }

    /** Show "create product" form. */
    public function create()
    {
        $categories = Category::orderBy('name')->get();

        return view('admin.products.create', compact('categories'));
    }

    /** Save a new product (+ optional image). */
    public function store(Request $request)
    {
        $data = $this->validateProduct($request);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('products', 'public');
        }

        $product = Product::create([
            'category_id' => $data['category_id'],
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($data['name']),
            'description' => $data['description'] ?? null,
            'specifications' => $data['specifications'] ?? null,
            'price' => $data['price'],
            'stock_quantity' => $data['stock_quantity'],
            'min_order_quantity' => $data['min_order_quantity'] ?? 1,
            'quantity_step' => $data['quantity_step'] ?? 1,
            'unit' => $data['unit'] ?? null,
            'brand' => $data['brand'] ?? null,
            'status' => $data['status'],
            'is_featured' => $request->boolean('is_featured'),
            'image' => $imagePath,
        ]);

        // First entry of the price history (§37).
        $product->priceHistory()->create([
            'old_price' => null,
            'new_price' => $product->price,
            'changed_by' => auth()->id(),
            'notes' => 'Initial price',
        ]);

        // §36 — a product born with stock gets its first adjustment row, so
        // the stock-adjustments screen shows the quantity from day one.
        $initialStock = (int) $product->stock_quantity;
        if ($initialStock > 0) {
            $product->adjustments()->create([
                'previous_quantity' => 0,
                'new_quantity' => $initialStock,
                'difference' => $initialStock,
                'reason' => 'Initial stock when the product was created',
                'adjusted_by' => auth()->id(),
            ]);
        }

        return redirect()->route('admin.products.index')->with('status', 'Product created successfully.');
    }

    /** Show edit form for a product. */
    public function edit(Product $product)
    {
        $categories = Category::orderBy('name')->get();
        $priceHistory = $product->priceHistory()->with('changedBy')->get();

        return view('admin.products.edit', compact('product', 'categories', 'priceHistory'));
    }

    /** Update a product (+ optional image replace/remove). */
    public function update(Request $request, Product $product, ActivityLogger $logger)
    {
        $data = $this->validateProduct($request, $product->id);

        // Image handling: remove / replace
        if ($request->boolean('remove_image')) {
            $this->deleteImage($product);
            $product->image = null;
        } elseif ($request->hasFile('image')) {
            $this->deleteImage($product);
            $product->image = $request->file('image')->store('products', 'public');
        }

        if ($product->name !== $data['name']) {
            $product->slug = $this->uniqueSlug($data['name'], $product->id);
        }

        $previousPrice = (float) $product->price;
        $previousStock = (int) $product->stock_quantity;

        $product->category_id = $data['category_id'];
        $product->name = $data['name'];
        $product->description = $data['description'] ?? null;
        $product->specifications = $data['specifications'] ?? null;
        $product->price = $data['price'];
        $product->stock_quantity = $data['stock_quantity'];
        $product->min_order_quantity = $data['min_order_quantity'] ?? 1;
        $product->quantity_step = $data['quantity_step'] ?? 1;
        $product->unit = $data['unit'] ?? null;
        $product->brand = $data['brand'] ?? null;
        $product->status = $data['status'];
        $product->is_featured = $request->boolean('is_featured');
        $product->save();

        // Stock changes made here are recorded exactly like changes made on
        // the Stock Adjustments screen (§36) — one history, two entrances.
        $newStock = (int) $product->stock_quantity;
        if ($newStock !== $previousStock) {
            $reason = 'Stock edited from the product screen';

            $product->adjustments()->create([
                'previous_quantity' => $previousStock,
                'new_quantity' => $newStock,
                'difference' => $newStock - $previousStock,
                'reason' => $reason,
                'adjusted_by' => auth()->id(),
            ]);

            $logger->log('stock.adjusted', $product, sprintf(
                'Stock for "%s" changed from %d to %d (%s).',
                $product->name,
                $previousStock,
                $newStock,
                (($newStock - $previousStock) > 0 ? '+' : '').($newStock - $previousStock)
            ), [
                'previous_quantity' => $previousStock,
                'new_quantity' => $newStock,
                'difference' => $newStock - $previousStock,
                'reason' => $reason,
            ]);
        }

        // Price changes are never silent (§37).
        $newPrice = (float) $product->price;
        if ($newPrice !== $previousPrice) {
            $product->priceHistory()->create([
                'old_price' => $previousPrice,
                'new_price' => $newPrice,
                'changed_by' => auth()->id(),
                'notes' => 'Price updated from the admin product screen',
            ]);

            // PHASE 12 — audit trail (Master Prompt §47).
            $logger->log('product.price_changed', $product, sprintf(
                'Price of "%s" changed from ₦%s to ₦%s.',
                $product->name,
                number_format($previousPrice, 0),
                number_format($newPrice, 0)
            ), [
                'old_price' => $previousPrice,
                'new_price' => $newPrice,
            ]);
        }

        return redirect()->route('admin.products.index')->with('status', 'Product updated successfully.');
    }

    /** Delete a product and its image file. */
    public function destroy(Product $product, ActivityLogger $logger)
    {
        // PHASE 12 — record the delete while the row still exists.
        $logger->log('product.deleted', $product, sprintf(
            'Product "%s" was deleted (price ₦%s, stock %d).',
            $product->name,
            number_format((float) $product->price, 0),
            (int) $product->stock_quantity
        ), [
            'price' => (float) $product->price,
            'stock_quantity' => (int) $product->stock_quantity,
        ]);

        $this->deleteImage($product);
        $product->delete();

        return redirect()->route('admin.products.index')->with('status', 'Product deleted.');
    }

    /* ------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------ */

    /** Shared validation for store + update. */
    private function validateProduct(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:150', Rule::unique('products', 'name')->ignore($ignoreId)],
            'description' => ['nullable', 'string', 'max:2000'],
            'specifications' => ['nullable', 'string', 'max:3000'],
            'brand' => ['nullable', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'min_order_quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'quantity_step' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'unit' => ['nullable', 'string', 'max:30'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ], [
            'min_order_quantity' => 'Minimum order quantity must be a whole number of at least 1.',
            'quantity_step' => 'Quantity step must be a whole number of at least 1.',
        ]);
    }

    /** Delete the product's image file from public disk (if any). */
    private function deleteImage(Product $product): void
    {
        if ($product->image && Storage::disk('public')->exists($product->image)) {
            Storage::disk('public')->delete($product->image);
        }
    }

    /** Unique slug generator (handles name collisions). */
    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $i = 2;

        while (
            Product::where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
