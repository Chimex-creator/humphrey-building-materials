<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A place inside a state (Mararaba, Nyanya, Lafia...). An area mapped to
 * an active zone prices the delivery with that zone's fee; otherwise the
 * state's default fee applies.
 */
class DeliveryArea extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_INACTIVE => 'Inactive (not serviceable)',
    ];

    protected $fillable = ['delivery_state_id', 'name', 'status', 'delivery_zone_id'];

    public function state(): BelongsTo
    {
        return $this->belongsTo(DeliveryState::class, 'delivery_state_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }
}
