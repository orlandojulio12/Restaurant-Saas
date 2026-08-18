<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedCost extends Model
{
    protected $table = 'fixed_costs';

    protected $fillable = [
        'restaurant_id',
        'name',
        'amount',
        'category',
        'frequency',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount'    => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Cuántas veces cabe cada frecuencia en un mes.
     *
     * 4.3333 = 52/12 semanas, 30.4167 = 365/12 días.
     */
    public const FACTOR_MENSUAL = [
        'daily'     => 30.4167,
        'weekly'    => 4.3333,
        'biweekly'  => 2.0,
        'monthly'   => 1.0,
        'quarterly' => 0.3333,
        'yearly'    => 0.0833,
    ];

    /**
     * Importe normalizado a un mes, que es la unidad con la que trabaja el
     * punto de equilibrio.
     */
    public function monthlyAmount(): float
    {
        return (float) $this->amount * (self::FACTOR_MENSUAL[$this->frequency] ?? 1.0);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
