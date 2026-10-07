<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual stock adjustment (§36) — the stock-take / discrepancy screen.
 *
 * Rules enforced here:
 *  - a reason is always required;
 *  - stock can never go negative;
 *  - every adjustment stores previous quantity, new quantity, difference,
 *    reason, who did it and when. Records are never edited or deleted.
 */
class StockAdjustmentController extends Controller
{
    /** Audit trail of past adjustments, newest first. */
    public function index(Request $request)
    {
        $search = $request->input('search');

        $query = StockAdjustment::with(['product', 'adjustedBy'])->latest('id');

        if ($search) {
            $query->whereHas('product', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        }

        $adjustments = $query->paginate(20)->withQueryString();

        return view('admin.stock-adjustments.index', compact('adjustments', 'search'));
    }

    /** Adjustment form — enter the counted quantity, the system works out the difference. */
    public function create(Request $request)
    {
        $products = Product::orderBy('name')->get(['id', 'name', 'unit', 'stock_quantity']);
        $preselected = $request->integer('product_id') ?: null;

        return view('admin.stock-adjustments.create', compact('products', 'preselected'));
    }

    public function store(Request $request, ActivityLogger $logger)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'new_quantity' => ['required', 'integer', 'min:0', 'max:10000000'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $adjustment = DB::transaction(function () use ($data) {
            $product = Product::whereKey($data['product_id'])->lockForUpdate()->firstOrFail();

            $previous = (int) $product->stock_quantity;
            $new = (int) $data['new_quantity'];
            $difference = $new - $previous;

            if ($difference === 0) {
                // Thrown (not flashed) so a double submit cannot record junk.
                throw ValidationException::withMessages([
                    'new_quantity' => 'That is already the current stock quantity — nothing to adjust.',
                ]);
            }

            if ($new < 0) {
                throw ValidationException::withMessages([
                    'new_quantity' => 'Stock quantity cannot be negative.',
                ]);
            }

            $adjustment = StockAdjustment::create([
                'product_id' => $product->id,
                'previous_quantity' => $previous,
                'new_quantity' => $new,
                'difference' => $difference,
                'reason' => $data['reason'],
                'adjusted_by' => auth()->id(),
            ]);

            $product->stock_quantity = $new;
            $product->save();

            return $adjustment;
        });

        // PHASE 12 — audit trail (Master Prompt 47).
        $logger->log('stock.adjusted', $adjustment->product, sprintf(
            'Stock for "%s" changed from %d to %d (%s).',
            $adjustment->product->name,
            $adjustment->previous_quantity,
            $adjustment->new_quantity,
            ($adjustment->difference > 0 ? '+' : '').$adjustment->difference
        ), [
            'previous_quantity' => (int) $adjustment->previous_quantity,
            'new_quantity' => (int) $adjustment->new_quantity,
            'difference' => (int) $adjustment->difference,
            'reason' => $adjustment->reason,
        ]);

        return redirect()
            ->route('admin.stock-adjustments.index')
            ->with('status', sprintf(
                'Stock adjusted from %d to %d (%s).',
                $adjustment->previous_quantity,
                $adjustment->new_quantity,
                ($adjustment->difference > 0 ? '+' : '').$adjustment->difference
            ));
    }
}
