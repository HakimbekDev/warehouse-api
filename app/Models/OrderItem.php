<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'batch_item_id', 'qty', 'sale_price'];

    protected $casts = [
        'qty' => 'integer',
        'sale_price' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function batchItem(): BelongsTo
    {
        return $this->belongsTo(BatchItem::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->where('type', Refund::TYPE_SALE);
    }

    /** Units of this line the client has not sent back. */
    public function refundableQty(): int
    {
        return (int) $this->qty - (int) $this->refunds()->sum('qty');
    }
}
