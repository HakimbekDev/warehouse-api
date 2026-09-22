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

    protected $fillable = ['batch_item_id', 'order_id', 'type', 'qty', 'unit_price', 'moved_at'];

    protected $casts = [
        'qty' => 'integer',
        'unit_price' => 'decimal:2',
        'moved_at' => 'date',
    ];

    public function batchItem(): BelongsTo
    {
        return $this->belongsTo(BatchItem::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** True for the two types that belong to an order and carry a sale price. */
    public function isSale(): bool
    {
        return $this->type === self::SALE || $this->type === self::SALE_REFUND;
    }
}
