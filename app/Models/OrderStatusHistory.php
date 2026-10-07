<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Audit trail of every order status change (who, when, from → to). */
class OrderStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id',
        'from_status',
        'to_status',
        'changed_by',
        'notes',
    ];

    protected $casts = [
        'from_status' => 'string',
        'to_status' => 'string',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function fromLabel(): string
    {
        return $this->from_status ? (Order::STATUSES[$this->from_status] ?? ucfirst($this->from_status)) : 'Order Created';
    }

    public function toLabel(): string
    {
        return Order::STATUSES[$this->to_status] ?? ucfirst($this->to_status);
    }
}
