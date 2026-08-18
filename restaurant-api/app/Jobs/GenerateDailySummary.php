<?php

namespace App\Jobs;

use App\Models\DailySummary;
use App\Models\Restaurant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Consolida las ventas de un día en daily_summaries.
 *
 * Es la fuente de datos de ReportController y de todo el módulo financiero.
 * Idempotente: se puede reprocesar cualquier fecha sin duplicar filas.
 */
class GenerateDailySummary implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int    $restaurantId,
        public readonly string $date,          // YYYY-MM-DD, día local del restaurante
    ) {}

    public function handle(): void
    {
        $restaurant = Restaurant::find($this->restaurantId);

        if (!$restaurant) {
            return;
        }

        // La app corre en UTC pero el día de negocio es el del restaurante.
        [$start, $end] = $restaurant->dayBoundsUtc($this->date);

        // Solo cuentan las órdenes cerradas: es cuando la venta se considera realizada.
        $orders = DB::table('orders')
            ->where('restaurant_id', $this->restaurantId)
            ->where('status', 'closed')
            ->whereBetween('closed_at', [$start, $end])
            ->select('id', 'type', 'total')
            ->get();

        $orderIds    = $orders->pluck('id');
        $totalOrders = $orders->count();
        $totalSales  = (float) $orders->sum('total');

        // Costo estimado: suma de products.cost por unidad vendida.
        $totalCost = $orderIds->isEmpty() ? 0.0 : (float) DB::table('order_items')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('order_items.order_id', $orderIds)
            ->selectRaw('SUM(products.cost * order_items.quantity) as cost')
            ->value('cost');

        // Producto más vendido del día (por unidades).
        $topProductId = $orderIds->isEmpty() ? null : DB::table('order_items')
            ->whereIn('order_id', $orderIds)
            ->select('product_id', DB::raw('SUM(quantity) as total_qty'))
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->value('product_id');

        DailySummary::updateOrCreate(
            [
                'restaurant_id' => $this->restaurantId,
                'date'          => $this->date,
            ],
            [
                'total_orders'    => $totalOrders,
                'total_sales'     => round($totalSales, 2),
                'total_cost'      => round($totalCost, 2),
                'gross_profit'    => round($totalSales - $totalCost, 2),
                'avg_ticket'      => $totalOrders > 0 ? round($totalSales / $totalOrders, 2) : 0,
                'orders_dine_in'  => $orders->where('type', 'dine_in')->count(),
                'orders_delivery' => $orders->where('type', 'delivery')->count(),
                'orders_counter'  => $orders->where('type', 'counter')->count(),
                'top_product_id'  => $topProductId,
            ]
        );
    }
}
