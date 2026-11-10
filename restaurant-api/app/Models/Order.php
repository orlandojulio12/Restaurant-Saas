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

    /**
     * Tipos que un comensal puede elegir desde el QR de su mesa.
     *
     * `delivery` y `counter` quedan fuera a propósito: los abre el personal, no
     * quien está sentado en el salón.
     */
    public const TIPOS_QR = ['dine_in', 'takeaway'];

    /**
     * Lo que le toca a cada comensal de una mesa.
     *
     * No hay que repartir nada a ojo: desde el QR **cada comensal abre su
     * propio pedido**, así que "lo de Ana" ya está separado en la base. Esto
     * solo agrupa por `diner_name` y suma.
     *
     * Dos decisiones que conviene no cambiar sin pensarlo:
     *
     * - Los pedidos **sin nombre van a un solo grupo**, no a uno cada uno. Si
     *   nadie escribió cómo se llama, no se puede saber si son la misma
     *   persona, y fingir que son tres deudores distintos sería inventarse un
     *   dato que no existe.
     * - Se agrupa por el nombre **normalizado** —sin espacios y en minúsculas—
     *   pero se muestra tal y como lo escribió el primero. "ana" y "Ana" son la
     *   misma persona sentada a la misma mesa.
     *
     * @param  \Illuminate\Support\Collection<int, self>  $pedidos
     * @return array<int, array{diner_name: ?string, orders: array<int,int>, total: float}>
     */
    public static function repartoPorComensal($pedidos): array
    {
        $grupos = [];

        foreach ($pedidos as $pedido) {
            $nombre = trim((string) $pedido->diner_name);
            $clave  = $nombre === '' ? '' : mb_strtolower($nombre);

            if (!isset($grupos[$clave])) {
                $grupos[$clave] = [
                    'diner_name' => $nombre === '' ? null : $nombre,
                    'orders'     => [],
                    'total'      => 0.0,
                ];
            }

            $grupos[$clave]['orders'][] = $pedido->id;
            $grupos[$clave]['total']   += (float) $pedido->total;
        }

        // El grupo sin nombre va al final: es el cajón de sastre, no un
        // comensal más, y encabezar la lista con él despista.
        uasort($grupos, fn($a, $b) => ($a['diner_name'] === null) <=> ($b['diner_name'] === null));

        return array_values(array_map(
            fn($g) => [...$g, 'total' => round($g['total'], 2)],
            $grupos,
        ));
    }

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
        'diner_name',
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
