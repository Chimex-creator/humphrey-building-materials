<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'specifications',
        'price',
        'stock_quantity',
        'min_order_quantity',
        'quantity_step',
        'image',
        'status',
        'unit',
        'brand',
        'is_featured',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_featured' => 'boolean',
        'min_order_quantity' => 'integer',
        'quantity_step' => 'integer',
    ];

    /** A product belongs to a category. */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class)->latest('id');
    }

    /** Manual stock corrections for this product (§36). */
    public function adjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class)->latest('id');
    }

    /** Inventory-related returns for this product (§40). */
    public function returns(): HasMany
    {
        return $this->hasMany(ProductReturn::class)->latest('id');
    }

    /** Is this product at or below the low-stock threshold? (§35) */
    public function isLowStock(int $threshold): bool
    {
        return $this->stock_quantity <= $threshold;
    }

    /** Whether the product is currently active (visible to customers). */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Whether the product is in stock. */
    public function inStock(): bool
    {
        return $this->stock_quantity > 0;
    }

    /** Smallest quantity a customer may order (defaults to 1). */
    public function minOrder(): int
    {
        return max(1, (int) $this->min_order_quantity);
    }

    /** Quantity increments allowed (1 = any quantity). */
    public function step(): int
    {
        return max(1, (int) $this->quantity_step);
    }

    /**
     * Why a requested quantity cannot be sold, or null when it is fine.
     * Keeps the rule in ONE place so cart, checkout and AJAX all agree.
     */
    public function quantityError(int $qty): ?string
    {
        if ($qty < 1) {
            return 'Quantity must be at least 1.';
        }

        $min = $this->minOrder();
        if ($qty < $min) {
            return '"'.$this->name.'" has a minimum order of '.$min
                .($this->unit ? ' '.$this->unit : '').'.';
        }

        $step = $this->step();
        if ($step > 1 && $qty % $step !== 0) {
            return '"'.$this->name.'" must be ordered in multiples of '.$step.'.';
        }

        if ($qty > (int) $this->stock_quantity) {
            return 'Only '.$this->stock_quantity.' left for "'.$this->name.'".';
        }

        return null;
    }
}
