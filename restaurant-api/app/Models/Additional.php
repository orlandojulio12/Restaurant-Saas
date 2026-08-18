<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Additional extends Model
{
    protected $fillable = [
        'group_id',
        'name',
        'extra_price',
        'is_available',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'extra_price'  => 'decimal:2',
            'is_available' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AdditionalGroup::class, 'group_id');
    }

    /**
     * Usos históricos en pedidos.
     *
     * order_item_additionals.additional_id es RESTRICT: un adicional que ya se
     * pidió alguna vez no se puede borrar, solo desactivar.
     */
    public function orderItemAdditionals(): HasMany
    {
        return $this->hasMany(OrderItemAdditional::class, 'additional_id');
    }
}
