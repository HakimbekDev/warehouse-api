<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = ['client_id', 'ordered_at'];

    protected $casts = ['ordered_at' => 'date'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Sales and client refunds of this order; there are no separate order lines. */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->orderBy('id');
    }
}
