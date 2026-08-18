<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemAdditional extends Model
{
    protected $table = 'order_item_additionals';

    protected $fillable = [
        'order_item_id',
        'additional_id',
        'additional_name',
        'extra_price',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function additional(): BelongsTo
    {
        return $this->belongsTo(Additional::class);
    }
}
