<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * Stock overview (§35) — the screen the inventory staff live on.
 * Shows quantity and selling price, with low/out filters driven by the
 * configurable low-stock threshold from Business Settings.
 */
class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        $categoryFilter = $request->input('category');
        $stockFilter = $request->input('stock');

        // Configurable threshold (§35) — same value the dashboard reads.
        $threshold = Setting::int('low_stock_threshold', 10);

        $query = Product::with('category')->orderBy('name');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($categoryFilter && is_numeric($categoryFilter)) {
            $query->where('category_id', (int) $categoryFilter);
        }

        if ($stockFilter === 'low') {
            $query->where('stock_quantity', '<=', $threshold);
        } elseif ($stockFilter === 'out') {
            $query->where('stock_quantity', '<=', 0);
        } elseif ($stockFilter === 'in') {
            $query->where('stock_quantity', '>', $threshold);
        }

        $products = $query->paginate(20)->withQueryString();

        $summary = [
            'total' => Product::count(),
            'units' => (int) Product::sum('stock_quantity'),
            'low' => Product::where('stock_quantity', '<=', $threshold)->count(),
            'out' => Product::where('stock_quantity', '<=', 0)->count(),
            'threshold' => $threshold,
        ];

        $categories = Category::orderBy('name')->get();

        return view('admin.inventory.index', compact(
            'products', 'categories', 'summary', 'search', 'categoryFilter', 'stockFilter'
        ));
    }
}
