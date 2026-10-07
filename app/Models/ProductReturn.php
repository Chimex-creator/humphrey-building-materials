<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A return — goods coming back into the business (§28 / §40).
 *
 * Named ProductReturn because `return` is a reserved word in PHP.
 *
 * Lifecycle (Final Spec §28):
 *   pending    → a customer asked; nobody has looked at it yet
 *   in_review  → staff started reviewing the request
 *   approved   → staff agreed the goods may come back
 *   rejected   → staff refused (terminal — no stock, no money)
 *   received   → the goods are physically back at the shop
 *   inspected  → quantities split into resellable / damaged
 *   completed  → resellable quantity restored to sellable stock
 *
 * There is NO monetary refund anywhere on this record (§27).
 * `restocked_at` set means the resellable quantity went back on the shelf;
 * damaged units never do.
 */
class ProductReturn extends Model
{
    protected $table = 'returns';

    public const STATUSES = [
        'pending' => 'Return Requested',
        'in_review' => 'In Review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'received' => 'Return Received',
        'inspected' => 'Inspected',
        'completed' => 'Return Completed',
    ];

    public const SOURCES = [
        'customer' => 'Customer Request',
        'staff' => 'Recorded by Staff',
    ];

    protected $fillable = [
        'product_id',
        'order_id',
        'quantity',
        'reason',
        'source',
        'status',
        'restocked_at',
        'returned_by',
        'reviewed_by',
        'reviewed_at',
        'received_by',
        'received_at',
        'inspected_by',
        'inspected_at',
        'resellable_quantity',
        'damaged_quantity',
        'inspection_note',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'resellable_quantity' => 'integer',
        'damaged_quantity' => 'integer',
        'restocked_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'received_at' => 'datetime',
        'inspected_at' => 'datetime',
    ];

    /* ------------------------------------------------------------
     | Relationships
     * ------------------------------------------------------------ */

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    /* ------------------------------------------------------------
     | Status helpers
     * ------------------------------------------------------------ */

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isInReview(): bool
    {
        return $this->status === 'in_review';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isReceived(): bool
    {
        return $this->status === 'received';
    }

    public function isInspected(): bool
    {
        return $this->status === 'inspected';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /** Open requests staff still have to review. */
    public function needsReview(): bool
    {
        return in_array($this->status, ['pending', 'in_review'], true);
    }

    /** Whether the physical goods are already back at the shop. */
    public function hasGoods(): bool
    {
        return $this->received_at !== null;
    }

    /** Whether the stock has already been added back into inventory. */
    public function isRestocked(): bool
    {
        return $this->restocked_at !== null;
    }

    public function isCustomerRequest(): bool
    {
        return $this->source === 'customer';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'pending' => 'badge-warn',
            'in_review' => 'badge-amber',
            'approved' => 'badge-amber',
            'rejected' => 'badge-red',
            'received' => 'badge-navy',
            'inspected' => 'badge-navy',
            'completed' => 'badge-green',
            default => 'badge',
        };
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? ucfirst($this->source);
    }

    /* ------------------------------------------------------------
     | Money (display only — no refunds anywhere)
     * ------------------------------------------------------------ */

    /**
     * Unit price the customer actually paid for this product on the order.
     * Null when the return is not tied to an order (free-form staff entry).
     */
    public function unitPrice(): ?float
    {
        if (! $this->order_id) {
            return null;
        }

        $item = $this->order->items()
            ->where('product_id', $this->product_id)
            ->first();

        return $item ? (float) $item->unit_price : null;
    }

    /** Value of the goods coming back at the price on the order. */
    public function goodsValue(): ?float
    {
        $unit = $this->unitPrice();

        return $unit === null ? null : round($unit * (int) $this->quantity, 2);
    }
}
