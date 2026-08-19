<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\RestaurantTable
 */
class TableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $activeOrder = $this->whenLoaded('orders', function () {
            $active = $this->orders
                ->whereIn('status', ['pending', 'preparing', 'ready', 'delivered'])
                ->first();

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
        ];
    }
}