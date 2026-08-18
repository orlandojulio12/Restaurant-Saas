<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'display_name',
        'max_tables',
        'max_products',
        'max_daily_orders',
        'has_whatsapp',
        'has_inventory',
        'has_reports',
        'has_financials',
        'price_monthly',
        'price_yearly',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'has_whatsapp'   => 'boolean',
            'has_inventory'  => 'boolean',
            'has_reports'    => 'boolean',
            'has_financials' => 'boolean',
            'is_active'      => 'boolean',
            'price_monthly'  => 'decimal:2',
            'price_yearly'   => 'decimal:2',
        ];
    }

    public function restaurants(): HasMany
    {
        return $this->hasMany(Restaurant::class);
    }
}
