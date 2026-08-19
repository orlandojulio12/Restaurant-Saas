<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    /**
     * Estados en los que el pedido todavía ocupa a alguien.
     *
     * Vivía repetida en TableResource y TableController, y al añadir 'proposed'
     * una de las copias se quedó atrás: la mesa salía ocupada pero sin mostrar
     * el pedido que el comensal acababa de mandar.
     */
    public const ACTIVOS = ['proposed', 'pending', 'preparing', 'ready', 'on_the_way', 'delivered'];

    /** Los que ya no se mueven. */
    public const TERMINALES = ['closed', 'cancelled'];

    protected $fillable = [
        'restaurant_id',
        'table_id',
        'customer_id',
        'user_id',
        'type',
        'status',
        'delivery_address',
        'delivery_notes',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total',
        'confirmed_at',
        'preparing_at',
        'ready_at',
        'delivered_at',
        'closed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'subtotal'        => 'decimal:2',
            'tax_amount'      => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total'           => 'decimal:2',
            'confirmed_at'    => 'datetime',
            'preparing_at'    => 'datetime',
            'ready_at'        => 'datetime',
            'delivered_at'    => 'datetime',
            'closed_at'       => 'datetime',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }
}
