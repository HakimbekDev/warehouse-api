<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BatchItem extends Model
{
    use HasFactory;

    protected $fillable = ['batch_id', 'product_id', 'qty', 'purchase_price'];

    protected $casts = [
        'qty' => 'integer',
        'purchase_price' => 'decimal:2',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Arrivals, refunds and sales of this line; its stock is their sum. */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
