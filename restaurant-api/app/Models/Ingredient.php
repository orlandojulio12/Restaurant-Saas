<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ingredient extends Model
{
    /** low_stock es derivado y el frontend lo necesita en cada listado. */
    protected $appends = ['low_stock'];

    protected $fillable = [
        'restaurant_id',
        'name',
        'unit',
        'stock',
        'min_stock',
        'cost_per_unit',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'stock'         => 'decimal:3',
            'min_stock'     => 'decimal:3',
            'cost_per_unit' => 'decimal:4',
            'is_active'     => 'boolean',
        ];
    }

    public function getLowStockAttribute(): bool
    {
        return $this->stock <= $this->min_stock;
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function productIngredients(): HasMany
    {
        return $this->hasMany(ProductIngredient::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
