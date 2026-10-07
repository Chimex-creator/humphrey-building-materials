<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /**
     * How the money arrives (Final Spec §18 — Paystack only; offline
     * payments are handled outside this website).
     */
    public const METHODS = [
        'paystack' => 'Paystack (Online)',
    ];

    /** Status of ONE payment row. */
    public const STATUSES = [
        'unpaid' => 'Unpaid',
        'pending' => 'Pending',
        'paid' => 'Paid',
        'failed' => 'Failed',
    ];

    /** Allowed next payment statuses (simple state machine — no skipping). */
    public const TRANSITIONS = [
        'unpaid' => ['pending', 'paid', 'failed'],
        'pending' => ['paid', 'failed'],
        'paid' => [],
        'failed' => ['unpaid'],
    ];

    protected $fillable = [
        'order_id',
        'amount',
        'method',
        'status',
        'reference',
        'transaction_id',
        'channel',
        'notes',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_id' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function allowedNextStatuses(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function isTerminal(): bool
    {
        return $this->allowedNextStatuses() === [];
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? ucfirst(str_replace('_', ' ', $this->method));
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'unpaid' => 'badge',
            'pending' => 'badge-warn',
            'paid' => 'badge-green',
            'failed' => 'badge-red',
            default => 'badge',
        };
    }
}
