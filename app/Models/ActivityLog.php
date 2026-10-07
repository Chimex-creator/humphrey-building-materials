<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PHASE 12 — one line of the audit trail (Master Prompt §47).
 *
 * `subject_label` is a snapshot of the record's name/order number so the
 * log still makes sense after the record itself is deleted.
 */
class ActivityLog extends Model
{
    /** Every action the app is allowed to write. */
    public const ACTIONS = [
        'stock.adjusted' => 'Stock Adjusted',
        'product.price_changed' => 'Price Changed',
        'product.deleted' => 'Product Deleted',
        'payment.recorded' => 'Payment Recorded',
        'payment.paid' => 'Payment Received',
        'payment.status_changed' => 'Payment Status Changed',
        'payment.verified' => 'Payment Verified',
        'order.cancelled' => 'Order Cancelled',
        'user.created' => 'Staff Created',
        'user.role_changed' => 'Role Changed',
        'user.deactivated' => 'Account Deactivated',
        'user.activated' => 'Account Activated',
        'settings.updated' => 'Settings Updated',
        'support.created' => 'Support Request Opened',
        'support.updated' => 'Support Request Updated',
        'delivery.fee_overridden' => 'Delivery Fee Overridden',
        'delivery.settings_updated' => 'Delivery Settings Updated',
        'delivery.status_changed' => 'Delivery Status Changed',
        'delivery.rescheduled' => 'Delivery Rescheduled',
    ];

    protected $table = 'activity_logs';

    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'subject_label',
        'description',
        'details',
        'ip_address',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? ucfirst(str_replace(['.', '_'], ' ', $this->action));
    }

    /** Badge colour per family of action. */
    public function actionBadgeClass(): string
    {
        return match (true) {
            str_starts_with($this->action, 'payment.') => 'badge-green',
            str_starts_with($this->action, 'order.') => 'badge-amber',
            str_starts_with($this->action, 'user.') => 'badge-navy',
            str_starts_with($this->action, 'stock.') || str_starts_with($this->action, 'product.') => 'badge-warn',
            str_starts_with($this->action, 'support.') => 'badge-green',
            default => 'badge',
        };
    }
}
