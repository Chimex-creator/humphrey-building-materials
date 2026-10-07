<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A state/territory the shop delivers to.
 *
 * `default_fee = null` (or inactive status) means the state is not
 * serviceable. For the 34 non-zone states the default fee is the price;
 * for FCT/Nasarawa (`uses_zones`) the price always comes from the area's
 * zone — never from this column (Final Spec §13).
 */
class DeliveryState extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE => 'Active (we deliver here)',
        self::STATUS_INACTIVE => 'Inactive (not serviceable)',
    ];

    protected $fillable = ['name', 'status', 'default_fee', 'uses_zones'];

    protected $casts = ['default_fee' => 'decimal:2', 'uses_zones' => 'boolean'];

    public function areas(): HasMany
    {
        return $this->hasMany(DeliveryArea::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isServiceable(): bool
    {
        return $this->isActive() && $this->default_fee !== null;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }
}
