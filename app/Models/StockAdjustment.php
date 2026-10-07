<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Manual stock correction (§36).
 *
 * The row itself is the audit trail — previous quantity, new quantity,
 * difference, reason, user and date/time are all stored and never edited.
 */
class StockAdjustment extends Model
{
    protected $fillable = [
        'product_id',
        'previous_quantity',
        'new_quantity',
        'difference',
        'reason',
        'adjusted_by',
    ];

    protected $casts = [
        'previous_quantity' => 'integer',
        'new_quantity' => 'integer',
        'difference' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }
}
