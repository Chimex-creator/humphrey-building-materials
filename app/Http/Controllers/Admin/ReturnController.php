<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Notifications\ReturnStatusUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Returns (§28 / §40).
 *
 * Two ways in:
 *   - a customer asks from their order page → lands as `pending`
 *   - staff record goods that are already back at the shop → `received`
 *
 * Workflow: Requested → In Review → Approved/Rejected → Return Received →
 *           Inspection → Return Completed.
 *
 * Stock only changes at COMPLETION, and only by the resellable quantity
 * found during inspection (§28/§40). Damaged units are recorded but never
 * restored. No monetary refunds exist anywhere (§27).
 */
class ReturnController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        $statusFilter = $request->input('status');
        $stockFilter = $request->input('stock');

        $query = ProductReturn::with(['product', 'order', 'returnedBy'])->latest('id');

        if ($search) {
            $query->whereHas('product', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        }

        if ($statusFilter && array_key_exists($statusFilter, ProductReturn::STATUSES)) {
            $query->where('status', $statusFilter);
        }

        if ($stockFilter === 'restocked') {
            $query->whereNotNull('restocked_at');
        } elseif ($stockFilter === 'pending') {
            $query->whereNull('restocked_at');
        }

        $returns = $query->paginate(20)->withQueryString();

        $summary = [
            'needs_review' => ProductReturn::whereIn('status', ['pending', 'in_review'])->count(),
            'approved' => ProductReturn::where('status', 'approved')->count(),
            'awaiting_inspection' => ProductReturn::whereIn('status', ['received', 'inspected'])->count(),
            'restocked' => ProductReturn::whereNotNull('restocked_at')->count(),
            'total' => ProductReturn::count(),
        ];

        return view('admin.returns.index', compact(
            'returns', 'search', 'statusFilter', 'stockFilter', 'summary'
        ));
    }

    public function create(Request $request)
    {
        $products = Product::orderBy('name')->get(['id', 'name', 'unit', 'stock_quantity']);
        $preselected = $request->integer('product_id') ?: null;

        return view('admin.returns.create', compact('products', 'preselected'));
    }

    /**
     * Staff record goods that have physically come back — they start at
     * "Return Received" because the boxes are already on the counter, then
     * MUST be inspected before any stock moves (§28).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000000'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'reason.min' => 'Give a reason for the return (at least 3 characters).',
        ]);

        ProductReturn::create([
            'product_id' => $data['product_id'],
            'quantity' => $data['quantity'],
            'reason' => $data['reason'],
            'source' => 'staff',
            'status' => 'received',
            'received_by' => auth()->id(),
            'received_at' => now(),
            'restocked_at' => null,
            'returned_by' => auth()->id(),
        ]);

        return redirect()->route('admin.returns.index')->with(
            'status',
            'Return recorded and marked as received. Inspect it to split resellable and damaged units.'
        );
    }

    /* ------------------------------------------------------------
     | Review — §28
     * ------------------------------------------------------------ */

    /** Staff starts reviewing a customer's request (pending → in review). */
    public function review(ProductReturn $return)
    {
        if (! $return->isPending()) {
            return back()->with('error', 'This return is not awaiting review.');
        }

        $return->update(['status' => 'in_review']);

        return back()->with('status', 'Return marked as in review.');
    }

    /** Accept a customer's return request. Stock stays a separate step. */
    public function approve(ProductReturn $return)
    {
        if (! $return->needsReview()) {
            return back()->with('error', 'This return has already been reviewed.');
        }

        $return->update([
            'status' => 'approved',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        if ($return->order?->user) {
            $return->order->user->notify(new ReturnStatusUpdated($return));
        }

        return back()->with(
            'status',
            'Return of '.$return->quantity.' × '.($return->product->name ?? 'item').' approved.'
            .' Mark it received when the goods arrive, then inspect it.'
        );
    }

    /** Refuse the request. No stock back, nothing further happens. */
    public function decline(ProductReturn $return)
    {
        if (! $return->needsReview()) {
            return back()->with('error', 'This return has already been reviewed.');
        }

        $return->update([
            'status' => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        if ($return->order?->user) {
            $return->order->user->notify(new ReturnStatusUpdated($return));
        }

        return back()->with('status', 'Return request rejected. No stock was added back.');
    }

    /** Approved return: the goods have physically arrived (→ received). */
    public function receive(ProductReturn $return)
    {
        if (! $return->isApproved()) {
            return back()->with('error', 'Only an approved return can be marked as received.');
        }

        $return->update([
            'status' => 'received',
            'received_by' => auth()->id(),
            'received_at' => now(),
        ]);

        return back()->with(
            'status',
            'Goods marked as received. Next step: inspection — split the quantity into resellable and damaged units.'
        );
    }

    /**
     * Inspection: the returned quantity must be split exactly into
     * resellable + damaged units (§28). Nothing touches stock yet.
     */
    public function inspect(Request $request, ProductReturn $return)
    {
        if (! $return->isReceived()) {
            return back()->with('error', 'Mark the goods as received before inspecting them.');
        }

        $data = $request->validate([
            'resellable_quantity' => ['required', 'integer', 'min:0'],
            'damaged_quantity' => ['required', 'integer', 'min:0'],
            'inspection_note' => ['nullable', 'string', 'max:500'],
        ]);

        $total = (int) $data['resellable_quantity'] + (int) $data['damaged_quantity'];

        if ($total !== (int) $return->quantity) {
            return back()->withInput()->withErrors([
                'resellable_quantity' => 'Resellable + damaged must add up to the returned quantity ('
                    .$return->quantity.').',
            ]);
        }

        $return->update([
            'status' => 'inspected',
            'inspected_by' => auth()->id(),
            'inspected_at' => now(),
            'resellable_quantity' => (int) $data['resellable_quantity'],
            'damaged_quantity' => (int) $data['damaged_quantity'],
            'inspection_note' => $data['inspection_note'] ?? null,
        ]);

        return back()->with(
            'status',
            'Inspection recorded: '.$return->resellable_quantity.' resellable, '
            .$return->damaged_quantity.' damaged. Complete the return to restore the resellable units.'
        );
    }

    /* ------------------------------------------------------------
     | Completion + stock
     * ------------------------------------------------------------ */

    /**
     * Finish the return: restore ONLY the resellable quantity to sellable
     * stock. Damaged units stay out of inventory (§28/§40).
     */
    public function complete(ProductReturn $return)
    {
        if (! $return->isInspected()) {
            return back()->with('error', 'Inspect this return before completing it.');
        }

        if ($return->isRestocked()) {
            return back()->with('error', 'Stock has already been restored for this return.');
        }

        $this->applyRestock($return);

        return redirect()->route('admin.returns.index')->with(
            'status',
            'Return completed — '.$return->resellable_quantity.' unit(s) restored to sellable stock'
            .($return->damaged_quantity > 0
                ? ', '.$return->damaged_quantity.' damaged unit(s) kept out of inventory.'
                : '.')
        );
    }

    /** Assumes the caller has already checked the return is inspected. */
    private function applyRestock(ProductReturn $return): void
    {
        DB::transaction(function () use ($return) {
            $restore = max(0, (int) $return->resellable_quantity);

            if ($restore > 0) {
                $product = Product::whereKey($return->product_id)->lockForUpdate()->first();

                if ($product) {
                    $product->stock_quantity = (int) $product->stock_quantity + $restore;
                    $product->save();
                }
            }

            $return->forceFill(['restocked_at' => now(), 'status' => 'completed'])->save();
        });
    }
}
