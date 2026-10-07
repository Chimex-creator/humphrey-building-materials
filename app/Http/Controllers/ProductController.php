<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Sort options: key => [label, column, direction].
     * (Class constants cannot hold closures — arrays of simple values are fine.)
     */
    private const SORTS = [
        'newest' => ['Newest', 'created_at', 'desc'],
        'name_asc' => ['Name A–Z', 'name', 'asc'],
        'price_asc' => ['Price: Low to High', 'price', 'asc'],
        'price_desc' => ['Price: High to Low', 'price', 'desc'],
    ];

    /**
     * Product catalogue: search, category filter, sort, in-stock toggle.
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:100',
            'sort' => 'nullable|string|max:20',
            'stock' => 'nullable|string|max:10',
            'min_price' => 'nullable|numeric|min:0|max:99999999',
            'max_price' => 'nullable|numeric|min:0|max:99999999',
        ], [
            'min_price.numeric' => 'Minimum price must be a number.',
            'max_price.numeric' => 'Maximum price must be a number.',
        ]);

        $search = $data['search'] ?? null;
        $categorySlug = $data['category'] ?? null;
        $sort = $data['sort'] ?? 'newest';
        $inStockOnly = ($data['stock'] ?? null) === 'in';
        $minPrice = isset($data['min_price']) && $data['min_price'] !== '' ? (float) $data['min_price'] : null;
        $maxPrice = isset($data['max_price']) && $data['max_price'] !== '' ? (float) $data['max_price'] : null;

        if (! array_key_exists($sort, self::SORTS)) {
            $sort = 'newest';
        }

        // Category pills (with active product counts for context).
        $categories = Category::withCount(['products as active_products_count' => function ($q) {
            $q->where('status', 'active');
        }])->orderBy('name')->get();

        $currentCategory = null;
        $invalidCategory = false;
        if ($categorySlug) {
            $currentCategory = Category::where('slug', $categorySlug)->first();
            if (! $currentCategory) {
                $invalidCategory = true;
            }
        }

        $query = Product::query()->with('category')->where('status', 'active');

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($invalidCategory) {
            $query->whereRaw('1 = 0');
        } elseif ($currentCategory) {
            $query->where('category_id', $currentCategory->id);
        }

        if ($inStockOnly) {
            $query->where('stock_quantity', '>', 0);
        }

        // Price range filter (§18). Swap if the shopper typed them backwards.
        if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
            [$minPrice, $maxPrice] = [$maxPrice, $minPrice];
        }
        if ($minPrice !== null) {
            $query->where('price', '>=', $minPrice);
        }
        if ($maxPrice !== null) {
            $query->where('price', '<=', $maxPrice);
        }

        // Apply the chosen sort (column + direction from SORTS map).
        [, $column, $direction] = self::SORTS[$sort];
        $query->orderBy($column, $direction);

        $products = $query->paginate(12)->withQueryString();
        $sortOptions = collect(self::SORTS)->map(fn ($v) => $v[0]);

        return view('products.index', compact(
            'categories', 'currentCategory', 'products', 'search', 'sort', 'sortOptions',
            'inStockOnly', 'minPrice', 'maxPrice'
        ));
    }

    /**
     * Single product details + related items from the same category.
     */
    public function show(string $slug)
    {
        $product = Product::with('category')
            ->where('slug', $slug)
            ->where('status', 'active')
            ->firstOrFail();

        // Related: same category, active, not this product, prefer in-stock.
        $related = Product::with('category')
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->where('status', 'active')
            ->orderByRaw('stock_quantity > 0 DESC')
            ->orderByDesc('is_featured')
            ->limit(4)
            ->get();

        // Contact details from Business Settings (not hardcoded).
        $phone = Setting::get('business_phone', '+2348153667923');
        $email = Setting::get('business_email', 'humphreybuildingmaterials@gmail.com');

        return view('products.show', compact('product', 'related', 'phone', 'email'));
    }
}
