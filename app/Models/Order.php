<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    /**
     * Order statuses (Final Spec §20 / §42).
     *
     * pending      = awaiting Paystack payment (created at checkout)
     * confirmed    = set AUTOMATICALLY once payment is server-side verified
     * then exactly one fulfillment chain runs:
     *   pickup:   confirmed → ready_for_pickup → picked_up
     *   delivery: confirmed → out_for_delivery → delivered
     */
    public const STATUSES = [
        'pending' => 'Awaiting Payment',
        'confirmed' => 'Confirmed',
        'ready_for_pickup' => 'Ready for Pickup',
        'picked_up' => 'Picked Up',
        'out_for_delivery' => 'Out for Delivery',
        'delivered' => 'Delivered',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
    ];

    /** Allowed next statuses (simple state machine — no skipping ahead). */
    public const TRANSITIONS = [
        // 'confirmed' is only ever set by verified payment (PaymentProcessor).
        'pending' => ['cancelled'],
        'confirmed' => ['ready_for_pickup', 'out_for_delivery', 'cancelled'],
        'ready_for_pickup' => ['picked_up', 'cancelled'],
        'picked_up' => ['closed'],
        'out_for_delivery' => ['delivered', 'cancelled'],
        'delivered' => ['closed'],
        'closed' => [],
        'cancelled' => [],
    ];

    /**
     * Payment statuses (order-level, recomputed from the payments rows).
     *
     * Final Spec §18/§27: Paystack only — no partial payment, no outstanding
     * balance, no customer credit and no website refunds.
     */
    public const PAYMENT_STATUSES = [
        'unpaid' => 'Unpaid',
        'pending' => 'Pending',
        'paid' => 'Paid',
        'failed' => 'Failed',
    ];

    /** How the customer pays (Final Spec §18 — Paystack only). */
    public const PAYMENT_OPTIONS = [
        'paystack' => 'Pay Online (Paystack)',
    ];

    /** Delivery fee lifecycle. */
    public const FEE_STATUSES = [
        'not_applicable' => 'Not Applicable (Pickup)',
        'pending_confirmation' => 'To Be Confirmed',
        'confirmed' => 'Confirmed',
    ];

    protected $fillable = [
        'order_number',
        'user_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'delivery_address',
        'delivery_option',
        'preferred_delivery_date',
        'notes',
        'status',
        'payment_option',
        'payment_status',
        'subtotal',
        'delivery_fee',
        'delivery_fee_status',
        'delivery_state_id',
        'delivery_area_id',
        'delivery_zone_id',
        'delivery_state_name',
        'delivery_area_name',
        'delivery_zone_name',
        'delivery_fee_source',
        'delivery_fee_note',
        'confirmed_by',
        'confirmed_at',
        'total',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'total' => 'decimal:2',
        'preferred_delivery_date' => 'date',
        'confirmed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /** Returns linked to this order (Phase 10). */
    public function returns(): HasMany
    {
        return $this->hasMany(ProductReturn::class)->latest('id');
    }

    /** Return requests on this order that nobody has finished reviewing yet. */
    public function pendingReturns(): HasMany
    {
        return $this->hasMany(ProductReturn::class)
            ->whereIn('status', ['pending', 'in_review'])
            ->latest('id');
    }

    public function hasPendingReturns(): bool
    {
        return $this->pendingReturns()->exists();
    }

    /** Delivery record — only delivery orders have one (Phase 7, §27). */
    public function delivery(): HasOne
    {
        return $this->hasOne(Delivery::class);
    }

    /** Most recent payment for this order. */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function latestPayment(): ?Payment
    {
        return $this->payments()->latest('id')->first();
    }

    /* ------------------------------------------------------------
     | Money helpers
     * ------------------------------------------------------------ */

    /** Total money successfully received for this order. */
    public function paidAmount(): float
    {
        return (float) $this->payments()->where('status', 'paid')->sum('amount');
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    public function isPayable(): bool
    {
        // Cannot pay after cancellation, while the delivery fee is still
        // being confirmed, or once the total has already been received.
        // Every order is settled in full through Paystack (Final Spec §18).
        return $this->status !== 'cancelled'
            && $this->status !== 'closed'
            && $this->delivery_fee_status !== 'pending_confirmation'
            && $this->payment_status !== 'paid'
            && (float) $this->total > 0;
    }

    public function feeConfirmed(): bool
    {
        return $this->delivery_fee_status === 'confirmed';
    }

    /* ------------------------------------------------------------
     | Status helpers
     * ------------------------------------------------------------ */

    /** Whether the order can be closed out (§41): finished with goods, nothing pending. */
    public function canBeClosed(): bool
    {
        return in_array($this->status, ['delivered', 'picked_up'], true)
            && ! $this->hasPendingReturns();
    }

    /** Statuses this order may move to next (empty = terminal). */
    public function allowedNextStatuses(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function isTerminal(): bool
    {
        return $this->allowedNextStatuses() === [];
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function paymentStatusLabel(): string
    {
        return self::PAYMENT_STATUSES[$this->payment_status] ?? ucfirst($this->payment_status);
    }

    public function paymentOptionLabel(): string
    {
        return self::PAYMENT_OPTIONS[$this->payment_option] ?? ucfirst(str_replace('_', ' ', $this->payment_option));
    }

    public function feeStatusLabel(): string
    {
        return self::FEE_STATUSES[$this->delivery_fee_status] ?? ucfirst($this->delivery_fee_status);
    }

    public function isDelivery(): bool
    {
        return $this->delivery_option === 'delivery';
    }

    /* ------------------------------------------------------------
     | Delivery geography (Phases 16–19)
     * ------------------------------------------------------------ */

    public function deliveryState(): BelongsTo
    {
        return $this->belongsTo(DeliveryState::class, 'delivery_state_id');
    }

    public function deliveryArea(): BelongsTo
    {
        return $this->belongsTo(DeliveryArea::class, 'delivery_area_id');
    }

    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }

    /**
     * Snapshot of where this order is going, e.g.
     * "Mararaba, Nasarawa (Zone 1)" — survives any later admin edits.
     */
    public function deliveryLocationLabel(): ?string
    {
        if (! $this->isDelivery()) {
            return null;
        }

        $place = collect([$this->delivery_area_name, $this->delivery_state_name])
            ->filter()
            ->implode(', ');

        if ($place === '') {
            return null;
        }

        return $place.($this->delivery_zone_name ? ' ('.$this->delivery_zone_name.')' : '');
    }

    /** Where the delivery fee number came from (for audit + staff UI). */
    public function deliveryFeeSourceLabel(): string
    {
        return match ($this->delivery_fee_source) {
            'zone' => 'Delivery zone rate',
            'state' => 'State delivery fee',
            'manual' => 'Manual override',
            'none' => 'No fee configured',
            default => '—',
        };
    }

    public function deliveryOptionLabel(): string
    {
        return $this->isDelivery() ? 'Delivery' : 'Pickup';
    }

    /** CSS class for the status badge. */
    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'pending' => 'badge-warn',
            'confirmed' => 'badge-amber',
            'ready_for_pickup' => 'badge-amber',
            'picked_up' => 'badge-green',
            'out_for_delivery' => 'badge-green',
            'delivered' => 'badge-green',
            'closed' => 'badge',
            'cancelled' => 'badge-red',
            default => 'badge',
        };
    }

    /** CSS class for the payment status badge. */
    public function paymentStatusBadgeClass(): string
    {
        return match ($this->payment_status) {
            'unpaid' => 'badge',
            'pending' => 'badge-warn',
            'paid' => 'badge-green',
            'failed' => 'badge-red',
            default => 'badge',
        };
    }
}
