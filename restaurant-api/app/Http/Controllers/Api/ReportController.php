<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reportes operativos.
 *
 * A diferencia del módulo financiero —que se apoya en daily_summaries para las
 * tendencias largas— aquí todo se calcula en vivo desde orders. Así los tres
 * endpoints reconcilian entre sí y con el día en curso del dashboard, sin
 * depender de si el job de consolidación ya corrió. A cambio, el rango se
 * limita para no barrer la tabla entera.
 */
class ReportController extends Controller
{
    private const MAX_DIAS = 366;

    public function daily(Request $request): JsonResponse
    {

        [$restaurant, $desde, $hasta, $error] = $this->rango($request);

        if ($error) {
            return $error;
        }

        $ordenes = $this->ordenesCerradas($restaurant, $desde, $hasta);
        $costos  = $this->costosPorOrden($ordenes->pluck('id'));
        $tz      = $restaurant->timezone ?: 'UTC';

        // Agrupado en PHP: el día de negocio es el local del restaurante y
        // agruparlo en SQL ataría la consulta al motor de base de datos.
        //
        // Se parte del valor crudo declarando UTC explícitamente en vez de
        // confiar en el cast: así la agrupación no depende de la zona horaria
        // ambiental del proceso.
        $porDia = $ordenes->groupBy(
            fn($o) => Carbon::parse($o->getRawOriginal('closed_at'), 'UTC')
                ->setTimezone($tz)
                ->toDateString()
        );

        $series = [];

        // Se recorre el rango completo para que los días sin ventas aparezcan
        // en cero en vez de faltar en la serie.
        for ($dia = $desde->copy(); $dia->lte($hasta); $dia->addDay()) {
            $fecha    = $dia->toDateString();
            $delDia   = $porDia->get($fecha, collect());
            $ventas   = (float) $delDia->sum('total');
            $costo    = (float) $delDia->sum(fn($o) => $costos[$o->id] ?? 0);
            $pedidos  = $delDia->count();

            $series[] = [
                'date'         => $fecha,
                'orders'       => $pedidos,
                'sales'        => round($ventas, 2),
                'cost'         => round($costo, 2),
                'gross_profit' => round($ventas - $costo, 2),
                'avg_ticket'   => $pedidos > 0 ? round($ventas / $pedidos, 2) : 0,
            ];
        }

        return response()->json([
            'from'   => $desde->toDateString(),
            'to'     => $hasta->toDateString(),
            'data'   => $series,
            'totals' => $this->totales($series),
        ]);
    }

    public function topProducts(Request $request): JsonResponse
    {

        [$restaurant, $desde, $hasta, $error] = $this->rango($request);

        if ($error) {
            return $error;
        }

        [$inicioUtc] = $restaurant->dayBoundsUtc($desde->toDateString());
        [, $finUtc]  = $restaurant->dayBoundsUtc($hasta->toDateString());

        $productos = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.restaurant_id', $restaurant->id)
            ->where('orders.status', 'closed')
            ->whereBetween('orders.closed_at', [$inicioUtc, $finUtc])
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->select(
                'order_items.product_id',
                'order_items.product_name',
                DB::raw('SUM(order_items.quantity) as total_qty'),
                DB::raw('SUM(order_items.subtotal) as total_revenue'),
                DB::raw('SUM(COALESCE(products.cost, 0) * order_items.quantity) as total_cost'),
            )
            ->orderByDesc('total_qty')
            ->limit((int) $request->input('limit', 10))
            ->get()
            ->map(fn($p) => [
                'product_id'    => $p->product_id,
                'product_name'  => $p->product_name,
                'quantity'      => (int) $p->total_qty,
                'revenue'       => round((float) $p->total_revenue, 2),
                'cost'          => round((float) $p->total_cost, 2),
                'gross_profit'  => round((float) $p->total_revenue - (float) $p->total_cost, 2),
            ]);

        return response()->json([
            'from' => $desde->toDateString(),
            'to'   => $hasta->toDateString(),
            'data' => $productos,
        ]);
    }

    public function summary(Request $request): JsonResponse
    {

        [$restaurant, $desde, $hasta, $error] = $this->rango($request);

        if ($error) {
            return $error;
        }

        $ordenes = $this->ordenesCerradas($restaurant, $desde, $hasta);
        $costos  = $this->costosPorOrden($ordenes->pluck('id'));

        $ventas  = (float) $ordenes->sum('total');
        $costo   = (float) $ordenes->sum(fn($o) => $costos[$o->id] ?? 0);
        $pedidos = $ordenes->count();

        [$inicioUtc] = $restaurant->dayBoundsUtc($desde->toDateString());
        [, $finUtc]  = $restaurant->dayBoundsUtc($hasta->toDateString());

        $porMetodo = Payment::where('restaurant_id', $restaurant->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$inicioUtc, $finUtc])
            ->groupBy('method')
            ->select('method', DB::raw('COUNT(*) as total'), DB::raw('SUM(amount) as amount'))
            ->get()
            ->map(fn($p) => [
                'method' => $p->method,
                'count'  => (int) $p->total,
                'amount' => round((float) $p->amount, 2),
            ]);

        return response()->json([
            'from'   => $desde->toDateString(),
            'to'     => $hasta->toDateString(),
            'totals' => [
                'orders'       => $pedidos,
                'sales'        => round($ventas, 2),
                'cost'         => round($costo, 2),
                'gross_profit' => round($ventas - $costo, 2),
                'margin_percent' => $ventas > 0 ? round((($ventas - $costo) / $ventas) * 100, 2) : 0,
                'avg_ticket'   => $pedidos > 0 ? round($ventas / $pedidos, 2) : 0,
            ],
            'by_type' => collect(['dine_in', 'delivery', 'counter'])->map(function ($tipo) use ($ordenes) {
                $delTipo = $ordenes->where('type', $tipo);

                return [
                    'type'   => $tipo,
                    'count'  => $delTipo->count(),
                    'sales'  => round((float) $delTipo->sum('total'), 2),
                ];
            })->values(),
            'by_payment_method' => $porMetodo,
        ]);
    }

    /**
     * Órdenes cerradas dentro del rango de días locales indicado.
     */
    private function ordenesCerradas(Restaurant $restaurant, Carbon $desde, Carbon $hasta): Collection
    {
        [$inicioUtc] = $restaurant->dayBoundsUtc($desde->toDateString());
        [, $finUtc]  = $restaurant->dayBoundsUtc($hasta->toDateString());

        return Order::where('restaurant_id', $restaurant->id)
            ->where('status', 'closed')
            ->whereBetween('closed_at', [$inicioUtc, $finUtc])
            ->get(['id', 'type', 'total', 'closed_at']);
    }

    /**
     * Costo estimado por orden, desde products.cost.
     *
     * @return array<int, float> order_id => costo
     */
    private function costosPorOrden(Collection $orderIds): array
    {
        if ($orderIds->isEmpty()) {
            return [];
        }

        return DB::table('order_items')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('order_items.order_id', $orderIds)
            ->groupBy('order_items.order_id')
            ->select('order_items.order_id', DB::raw('SUM(products.cost * order_items.quantity) as costo'))
            ->pluck('costo', 'order_id')
            ->map(fn($c) => (float) $c)
            ->all();
    }

    /**
     * @return array{0: Restaurant, 1: Carbon, 2: Carbon, 3: ?JsonResponse}
     */
    private function rango(Request $request): array
    {
        $restaurant = Restaurant::findOrFail($request->input('restaurant_id'));
        $localNow   = $restaurant->localNow();
        $tz         = $restaurant->timezone ?: 'UTC';

        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to'   => ['nullable', 'date_format:Y-m-d'],
        ]);

        $hasta = $request->filled('to')
            ? Carbon::parse($request->input('to'), $tz)->startOfDay()
            : $localNow->copy()->startOfDay();

        $desde = $request->filled('from')
            ? Carbon::parse($request->input('from'), $tz)->startOfDay()
            : $hasta->copy()->subDays(29);

        if ($desde->gt($hasta)) {
            return [$restaurant, $desde, $hasta, response()->json([
                'message' => 'La fecha inicial no puede ser posterior a la final.',
            ], 422)];
        }

        // Sin tope, un rango amplio barrería toda la tabla de pedidos.
        if ($desde->diffInDays($hasta) + 1 > self::MAX_DIAS) {
            return [$restaurant, $desde, $hasta, response()->json([
                'message'  => 'El rango no puede superar ' . self::MAX_DIAS . ' días.',
                'max_days' => self::MAX_DIAS,
            ], 422)];
        }

        return [$restaurant, $desde, $hasta, null];
    }

    private function totales(array $series): array
    {
        $pedidos = array_sum(array_column($series, 'orders'));
        $ventas  = array_sum(array_column($series, 'sales'));
        $costo   = array_sum(array_column($series, 'cost'));

        return [
            'orders'       => $pedidos,
            'sales'        => round($ventas, 2),
            'cost'         => round($costo, 2),
            'gross_profit' => round($ventas - $costo, 2),
            'avg_ticket'   => $pedidos > 0 ? round($ventas / $pedidos, 2) : 0,
        ];
    }

}
