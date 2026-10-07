<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_INVENTORY = 'inventory';

    public const ROLE_SALES = 'sales';

    public const ROLE_CUSTOMER = 'customer';

    public const ROLES = [
        self::ROLE_ADMIN => 'Admin',
        self::ROLE_INVENTORY => 'Inventory Staff',
        self::ROLE_SALES => 'Sales Staff',
        self::ROLE_CUSTOMER => 'Customer',
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'address',
        'avatar_path',
        'is_active',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed', // Laravel hashes automatically on save
            'is_active' => 'boolean',
        ];
    }

    /* ------------------------------------------------------------
     | Role helpers
     | ------------------------------------------------------------ */

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isInventory(): bool
    {
        return $this->role === self::ROLE_INVENTORY;
    }

    public function isSales(): bool
    {
        return $this->role === self::ROLE_SALES;
    }

    public function isCustomer(): bool
    {
        return $this->role === self::ROLE_CUSTOMER;
    }

    public function isStaff(): bool
    {
        return in_array($this->role, [
            self::ROLE_ADMIN,
            self::ROLE_INVENTORY,
            self::ROLE_SALES,
        ], true);
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? ucfirst($this->role);
    }

    /** Public URL of the profile picture, or null when none is uploaded. */
    public function avatarUrl(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->exists($this->avatar_path)
            ? asset('storage/'.$this->avatar_path)
            : null;
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Stock adjustments this staff member made (§36). */
    public function adjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class, 'adjusted_by');
    }

    /** Returns this staff member recorded (§40). */
    public function returns(): HasMany
    {
        return $this->hasMany(ProductReturn::class, 'returned_by');
    }
}
