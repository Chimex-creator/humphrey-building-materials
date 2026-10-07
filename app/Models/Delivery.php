<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PHASE 7 (completion) - a delivery record (Final Spec §22/§45).
 *
 * One per delivery order. Holds the operational facts: who is taking it,
 * the date we promised and notes if it went wrong. There are NO trips.
 *
 * The customer-facing lifecycle is still the ORDER status. When a delivery
 * status maps onto an order status, the controllers move both so the two
 * screens can never tell a different story.
 */
class Delivery extends Model
{
    protected $fillable = [
        'order_id',
        'assigned_to',
        'status',
        'confirmed_date',
        'notes',
        'failure_reason',
        'failure_note',
        'rescheduled_date',
        'delivered_at',
        'received_by',
        'confirmation_note',
    ];

    public const STATUSES = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed / Assigned',
        'out_for_delivery' => 'Out for Delivery',
        'delivered' => 'Delivered',
        'failed' => 'Failed / Rescheduled',
    ];

    /** Why an attempt did not complete (Phases 21–24). */
    public const FAILURE_REASONS = [
        'customer_unavailable' => 'Customer unavailable',
        'wrong_address' => 'Wrong / incomplete address',
        'refused' => 'Customer refused delivery',
        'vehicle_issue' => 'Vehicle / breakdown issue',
        'weather' => 'Weather or road condition',
        'other' => 'Other (write a note)',
    ];

    /** Delivery statuses that are also valid order statuses. */
    public const ORDER_MAP = [
        'out_for_delivery' => 'out_for_delivery',
        'delivered' => 'delivered',
    ];

    protected $casts = [
        'confirmed_date' => 'date',
        'rescheduled_date' => 'date',
        'delivered_at' => 'datetime',
    ];

    /* ------------------------------------------------------------
     | Relationships
     * ------------------------------------------------------------ */

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /* ------------------------------------------------------------
     | Display helpers
     * ------------------------------------------------------------ */

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    public function failureReasonLabel(): ?string
    {
        if ($this->failure_reason === null) {
            return null;
        }

        return self::FAILURE_REASONS[$this->failure_reason]
            ?? ucfirst(str_replace('_', ' ', $this->failure_reason));
    }

    /** True when a failed attempt already has a replacement date agreed. */
    public function hasReschedule(): bool
    {
        return $this->rescheduled_date !== null;
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'pending' => 'badge-warn',
            'confirmed' => 'badge-amber',
            'out_for_delivery' => 'badge-navy',
            'delivered' => 'badge-green',
            'failed' => 'badge-red',
            default => 'badge',
        };
    }
}
