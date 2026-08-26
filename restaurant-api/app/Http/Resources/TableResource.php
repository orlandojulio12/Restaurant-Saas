<?php

namespace App\Http\Resources;

use App\Models\Order;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\RestaurantTable
 */
class TableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Desde que cada comensal pide por su cuenta con el QR, una mesa puede
        // tener varios pedidos abiertos a la vez. `active_order` sigue siendo
        // uno solo —el que reclama atención— pero el dinero y el conteo tienen
        // que ser de la mesa entera: con `first()` a secas, el plano mostraba
        // el total de un pedido en una mesa que debía la suma de tres.
        $activos = $this->whenLoaded(
            'orders',
            fn() => $this->orders->whereIn('status', Order::ACTIVOS)->sortBy('id')->values(),
            collect(),
        );

        $activeOrder = $this->whenLoaded('orders', function () use ($activos) {
            // Una propuesta manda sobre el reloj: es lo único que espera a que
            // el mesero haga algo. Si no la hay, el más viejo.
            $active = $activos->firstWhere('status', 'proposed') ?? $activos->first();

            if (!$active) {
                return null;
            }

            return [
                'id'          => $active->id,
                'status'      => $active->status,
                'total'       => $active->total,
                'items_count' => $active->items_count ?? $active->items->count(),
                // Del pasado hacia ahora: al revés, Carbon 3 devuelve el
                // valor en negativo y el panel muestra "-48 min".
                'elapsed_min' => $active->created_at
                    ? (int) $active->created_at->diffInMinutes(now())
                    : null,
            ];
        });

        return [
            'id'           => $this->id,
            'number'       => $this->number,
            'capacity'     => $this->capacity,
            'status'       => $this->status,
            'qr_code'      => $this->qr_code,
            'zone'         => $this->whenLoaded('zone', fn() => $this->zone ? [
                'id'   => $this->zone->id,
                'name' => $this->zone->name,
            ] : null),
            'active_order' => $activeOrder,
            // Lo que la mesa lleva en total, no lo que lleva un comensal.
            'active_orders_count' => $this->whenLoaded('orders', fn() => $activos->count()),
            'active_total'        => $this->whenLoaded(
                'orders',
                fn() => number_format($activos->sum(fn($o) => (float) $o->total), 2, '.', ''),
            ),
        ];
    }
}