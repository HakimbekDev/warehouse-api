<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    use HasFactory;

    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_SALE = 'sale';

    protected $fillable = ['type', 'batch_item_id', 'order_item_id', 'qty', 'refunded_at'];

    protected $casts = [
        'qty' => 'integer',
        'refunded_at' => 'date',
    ];
}
