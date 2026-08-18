<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailySummary extends Model
{
    protected $table = 'daily_summaries';

    protected $fillable = [
        'restaurant_id',
        'date',
        'total_orders',
        'total_sales',
        'total_cost',
        'gross_profit',
        'avg_ticket',
        'orders_dine_in',
        'orders_delivery',
        'orders_counter',
        'top_product_id',
    ];

    protected function casts(): array
    {
        return [
            'date'         => 'date:Y-m-d',
            'total_sales'  => 'decimal:2',
            'total_cost'   => 'decimal:2',
            'gross_profit' => 'decimal:2',
            'avg_ticket'   => 'decimal:2',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function topProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'top_product_id');
    }
}
