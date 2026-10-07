<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 6 — one customer care record (complaint or enquiry).
 *
 * Status is the actual business state of the complaint. Staff move it
 * forward deliberately; nothing in the system auto-resolves a ticket.
 */
class SupportTicket extends Model
{
    public const CATEGORIES = [
        'order_issue' => 'Order Issue',
        'delivery_issue' => 'Delivery Issue',
        'product_issue' => 'Product Issue',
        'payment_issue' => 'Payment Issue',
        'return_issue' => 'Return Issue',
        'general_enquiry' => 'General Enquiry',
        'other' => 'Other',
    ];

    /** Final Spec §42 — Customer Care uses exactly three statuses. */
    public const STATUSES = [
        'open' => 'Open',
        'in_progress' => 'In Progress',
        'resolved' => 'Resolved',
    ];

    protected $fillable = [
        'reference',
        'user_id',
        'order_id',
        'product_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'category',
        'subject',
        'description',
        'status',
        'staff_response',
        'responded_by',
        'responded_at',
        'resolved_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /* ------------------------------------------------------------
     | Relationships
     * ------------------------------------------------------------ */

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    /* ------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------ */

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst(str_replace('_', ' ', $this->category));
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'in_progress'], true);
    }

    public function badgeClass(): string
    {
        return match ($this->status) {
            'open' => 'badge-amber',
            'in_progress' => 'badge-navy',
            'resolved' => 'badge-green',
            default => 'badge',
        };
    }
}
