<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Punto único donde se decide qué permite el plan de un restaurante.
 *
 * Antes cada controlador traía su propia comprobación; aquí quedan juntas las
 * dos formas de límite: funciones activadas o no (has_*) y cupos numéricos
 * (max_*), donde 0 significa ilimitado.
 */
class PlanService
{
    /** Funciones del plan, tal como se llaman en la tabla plans. */
    public const FEATURES = ['whatsapp', 'inventory', 'reports', 'financials'];

    private const RECURSOS = [
        'tables'       => ['columna' => 'max_tables',       'etiqueta' => 'mesas'],
        'products'     => ['columna' => 'max_products',     'etiqueta' => 'productos'],
        'daily_orders' => ['columna' => 'max_daily_orders', 'etiqueta' => 'pedidos por día'],
    ];

    public function allows(Restaurant $restaurant, string $feature): bool
    {
        $restaurant->loadMissing('plan');

        return (bool) ($restaurant->plan?->{"has_{$feature}"} ?? false);
    }

    /**
     * Cupo del plan para un recurso. 0 = ilimitado.
     */
    public function limitFor(Restaurant $restaurant, string $recurso): int
    {
        $restaurant->loadMissing('plan');

        return (int) ($restaurant->plan?->{self::RECURSOS[$recurso]['columna']} ?? 0);
    }

    /**
     * Consumo actual del recurso.
     */
    public function usageFor(Restaurant $restaurant, string $recurso): int
    {
        return match ($recurso) {
            'tables'   => RestaurantTable::where('restaurant_id', $restaurant->id)->count(),
            'products' => Product::where('restaurant_id', $restaurant->id)->count(),
            // El cupo diario se mide contra el día local del restaurante, no
            // contra el día UTC del servidor.
            'daily_orders' => $this->pedidosDeHoy($restaurant),
        };
    }

    public function hasRoomFor(Restaurant $restaurant, string $recurso): bool
    {
        $limite = $this->limitFor($restaurant, $recurso);

        return $limite <= 0 || $this->usageFor($restaurant, $recurso) < $limite;
    }

    /**
     * Corta la petición con 403 si no queda cupo.
     */
    public function assertRoomFor(Restaurant $restaurant, string $recurso): void
    {
        if ($this->hasRoomFor($restaurant, $recurso)) {
            return;
        }

        $limite   = $this->limitFor($restaurant, $recurso);
        $actual   = $this->usageFor($restaurant, $recurso);
        $etiqueta = self::RECURSOS[$recurso]['etiqueta'];

        throw new HttpResponseException(response()->json([
            'message'  => "Tu plan permite {$limite} {$etiqueta} y ya llegaste al límite. "
                . 'Actualiza de plan para seguir.',
            'resource' => $recurso,
            'limit'    => $limite,
            'current'  => $actual,
        ], 403));
    }

    private function pedidosDeHoy(Restaurant $restaurant): int
    {
        [$inicio, $fin] = $restaurant->dayBoundsUtc($restaurant->localNow()->toDateString());

        return Order::where('restaurant_id', $restaurant->id)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$inicio, $fin])
            ->count();
    }
}
