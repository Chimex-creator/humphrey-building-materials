<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named delivery fee band (Zone 1 = ₦5,000 ...). Areas mapped to an
 * active zone take the zone fee instead of the state's default fee.
 */
class DeliveryZone extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_INACTIVE => 'Inactive (not serviceable)',
    ];

    protected $fillable = ['name', 'zone_number', 'fee', 'status'];

    protected $casts = ['fee' => 'decimal:2'];

    public function areas(): HasMany
    {
        return $this->hasMany(DeliveryArea::class);
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
