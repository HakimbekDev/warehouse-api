<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use HasFactory;

    public const PURCHASE = 'purchase';
    public const PURCHASE_REFUND = 'purchase_refund';
    public const SALE = 'sale';
    public const SALE_REFUND = 'sale_refund';

    protected $fillable = ['batch_item_id', 'order_item_id', 'type', 'qty', 'moved_at'];

    protected $casts = [
        'qty' => 'integer',
        'moved_at' => 'date',
    ];

    public function batchItem(): BelongsTo
    {
        return $this->belongsTo(BatchItem::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** True for the two types that carry a sale price. */
    public function isSale(): bool
    {
        return $this->type === self::SALE || $this->type === self::SALE_REFUND;
    }
}
