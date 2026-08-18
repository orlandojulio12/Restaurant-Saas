<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FinancialDashboardResource;
use App\Models\DailySummary;
use App\Models\FinancialGoal;
use App\Models\FixedCost;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialController extends Controller
{
    public function breakeven(Request $request): JsonResponse
    {
        $restaurantId = $request->input('restaurant_id');

        // Costos fijos mensuales normalizados
        $costs = FixedCost::where('restaurant_id', $restaurantId)
            ->where('is_active', true)
            ->get();

        // La normalización a mes vive en el modelo: antes 'daily' no estaba
        // contemplado aquí y un costo diario se sumaba como si fuera mensual.
        $monthlyFixed = $costs->sum(fn($c) => $c->monthlyAmount());

        // Ticket promedio del último mes
        $lastMonth   = now()->subDays(30);
        $summaries   = DailySummary::where('restaurant_id', $restaurantId)
            ->where('date', '>=', $lastMonth->toDateString())
            ->get();

        $totalSales  = $summaries->sum('total_sales');
        $totalOrders = $summaries->sum('total_orders');
        $avgTicket   = $totalOrders > 0 ? $totalSales / $totalOrders : 0;

        // Margen promedio (ventas - costo) / ventas de los últimos 30 días
        $totalCost   = $summaries->sum('total_cost');
        $marginPct   = $totalSales > 0
            ? (($totalSales - $totalCost) / $totalSales) * 100
            : 0;

        // Punto de equilibrio: costos_fijos / margen
        $breakevenRevenue = $marginPct > 0
            ? $monthlyFixed / ($marginPct / 100)
            : 0;

        $breakevenCustomersMonth = $avgTicket > 0
            ? ceil($breakevenRevenue / $avgTicket)
            : 0;

        $breakevenCustomersDay = ceil($breakevenCustomersMonth / 30);

        return response()->json([
            'monthly_fixed_costs'            => round($monthlyFixed, 2),
            'avg_ticket'                     => round($avgTicket, 2),
            'avg_margin_percent'             => round($marginPct, 2),
            'breakeven_revenue'              => round($breakevenRevenue, 2),
            'breakeven_customers_per_month'  => $breakevenCustomersMonth,
            'breakeven_customers_per_day'    => $breakevenCustomersDay,
            'current_monthly_revenue'        => round($totalSales, 2),
            'gap'                            => round($breakevenRevenue - $totalSales, 2),
        ]);
    }

    public function projection(Request $request): JsonResponse
    {
        $restaurantId = $request->input('restaurant_id');
        $restaurant   = Restaurant::findOrFail($restaurantId);

        $goal = FinancialGoal::where('restaurant_id', $restaurantId)->first();

        $localNow   = $restaurant->localNow();
        $today      = $localNow->toDateString();
        $yesterday  = $localNow->copy()->subDay()->toDateString();
        $monthStart = $localNow->copy()->startOfMonth()->toDateString();

        // Ventas últimos 7 días
        $last7 = DailySummary::where('restaurant_id', $restaurantId)
            ->where('date', '>=', $localNow->copy()->subDays(7)->toDateString())
            ->sum('total_sales');

        $dailyAvg = $last7 / 7;

        // Mes en curso: días consolidados + hoy en vivo (mismo criterio que el dashboard).
        $currentMonthSales = (float) DailySummary::where('restaurant_id', $restaurantId)
            ->whereBetween('date', [$monthStart, $yesterday])
            ->sum('total_sales')
            + $this->liveDay($restaurant, $today)['sales'];

        $daysInMonth    = (int) $localNow->daysInMonth;
        $dayOfMonth     = (int) $localNow->day;
        $daysRemaining  = $daysInMonth - $dayOfMonth;

        $projectedMonth = $currentMonthSales + ($dailyAvg * $daysRemaining);

        // Punto de equilibrio
        $breakevenData  = json_decode($this->breakeven($request)->getContent(), true);
        $breakeven      = $breakevenData['breakeven_revenue'];
        $target         = $goal ? (float) $goal->target_monthly_revenue : 0;

        $dailyNeeded = $daysRemaining > 0 && $target > 0
            ? max(0, ($target - $currentMonthSales) / $daysRemaining)
            : 0;

        return response()->json([
            'current_month_sales'         => round($currentMonthSales, 2),
            'projected_month_sales'       => round($projectedMonth, 2),
            'target_monthly_revenue'      => $target,
            'breakeven'                   => round($breakeven, 2),
            'on_track'                    => $projectedMonth >= $breakeven,
            'daily_needed_to_reach_target'=> round($dailyNeeded, 2),
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $restaurantId = $request->input('restaurant_id');
        $restaurant   = Restaurant::findOrFail($restaurantId);

        $localNow       = $restaurant->localNow();
        $today          = $localNow->toDateString();
        $yesterday      = $localNow->copy()->subDay()->toDateString();
        $monthStart     = $localNow->copy()->startOfMonth()->toDateString();
        $lastMonthStart = $localNow->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $lastMonthEnd   = $localNow->copy()->subMonthNoOverflow()->endOfMonth()->toDateString();

        // Hoy y ayer se calculan EN VIVO contra orders: daily_summaries solo se escribe
        // para días ya cerrados, así que leer de ahí daba siempre 0 en el día en curso.
        $todayLive     = $this->liveDay($restaurant, $today);
        $yesterdayLive = $this->liveDay($restaurant, $yesterday);

        $todaySales     = $todayLive['sales'];
        $yesterdaySales = $yesterdayLive['sales'];

        $salesChangeToday = $yesterdaySales > 0
            ? round((($todaySales - $yesterdaySales) / $yesterdaySales) * 100, 1)
            : null;

        // Mes en curso: días ya consolidados + el día de hoy en vivo.
        $consolidatedMonthSales = (float) DailySummary::where('restaurant_id', $restaurantId)
            ->whereBetween('date', [$monthStart, $yesterday])
            ->sum('total_sales');

        $currentMonthSales = $consolidatedMonthSales + $todaySales;

        $lastMonthSales = (float) DailySummary::where('restaurant_id', $restaurantId)
            ->whereBetween('date', [$lastMonthStart, $lastMonthEnd])
            ->sum('total_sales');

        $salesChangeMoM = $lastMonthSales > 0
            ? round((($currentMonthSales - $lastMonthSales) / $lastMonthSales) * 100, 1)
            : null;

        $ordersToday    = $todayLive['orders'];
        $avgTicketToday = $todayLive['avg_ticket'];

        // Punto de equilibrio del mes
        $breakevenData    = json_decode($this->breakeven($request)->getContent(), true);
        $breakevenRevenue = $breakevenData['breakeven_revenue'];
        $gapToBreakeven   = round($breakevenRevenue - $currentMonthSales, 2);

        // El mes arranca en el inicio del día local, convertido a UTC.
        [$monthStartUtc] = $restaurant->dayBoundsUtc($monthStart);

        // Top 3 productos del mes, sobre ventas realizadas (órdenes cerradas),
        // para que total_revenue cuadre con month.sales.
        $topProducts = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.restaurant_id', $restaurantId)
            ->where('orders.status', 'closed')
            ->where('orders.closed_at', '>=', $monthStartUtc)
            ->select(
                'order_items.product_id',
                'order_items.product_name',
                DB::raw('SUM(order_items.quantity) as total_qty'),
                DB::raw('SUM(order_items.subtotal) as total_revenue')
            )
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->orderByDesc('total_qty')
            ->limit(3)
            ->get();

        // Método de pago más usado
        $topPaymentMethod = Payment::where('restaurant_id', $restaurantId)
            ->where('created_at', '>=', $monthStartUtc)
            ->select('method', DB::raw('COUNT(*) as count'))
            ->groupBy('method')
            ->orderByDesc('count')
            ->value('method');

        $data = [
            'today' => [
                'date'           => $today,
                'sales'          => $todaySales,
                'orders'         => $ordersToday,
                'open_orders'    => $todayLive['open_orders'],
                'avg_ticket'     => $avgTicketToday,
                'vs_yesterday'   => [
                    'sales'          => $yesterdaySales,
                    'change_percent' => $salesChangeToday,
                ],
            ],
            'month' => [
                'sales'              => round($currentMonthSales, 2),
                'vs_last_month'      => [
                    'sales'          => round($lastMonthSales, 2),
                    'change_percent' => $salesChangeMoM,
                ],
                'breakeven'          => round($breakevenRevenue, 2),
                'gap_to_breakeven'   => $gapToBreakeven,
                'above_breakeven'    => $currentMonthSales >= $breakevenRevenue,
            ],
            'top_products'       => $topProducts,
            'top_payment_method' => $topPaymentMethod,
        ];

        return response()->json(new FinancialDashboardResource($data));
    }

    /**
     * Métricas en vivo de un día local, leídas de orders.
     *
     * Se usa para el día en curso (y para ayer, si el job aún no corrió):
     * daily_summaries solo cubre días ya consolidados.
     *
     * @return array{sales: float, orders: int, open_orders: int, avg_ticket: float}
     */
    private function liveDay(Restaurant $restaurant, string $date): array
    {
        [$start, $end] = $restaurant->dayBoundsUtc($date);

        // Venta realizada = orden cerrada, igual que en GenerateDailySummary.
        $closed = Order::where('restaurant_id', $restaurant->id)
            ->where('status', 'closed')
            ->whereBetween('closed_at', [$start, $end])
            ->selectRaw('COUNT(*) as orders_count, COALESCE(SUM(total), 0) as sales')
            ->first();

        // Pedidos abiertos: aún no cerrados ni cancelados. No suman a ventas.
        $openOrders = Order::where('restaurant_id', $restaurant->id)
            ->whereNotIn('status', ['closed', 'cancelled'])
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $orders = (int) $closed->orders_count;
        $sales  = (float) $closed->sales;

        return [
            'sales'       => round($sales, 2),
            'orders'      => $orders,
            'open_orders' => $openOrders,
            'avg_ticket'  => $orders > 0 ? round($sales / $orders, 2) : 0,
        ];
    }

    public function updateGoals(Request $request): JsonResponse
    {
        $restaurantId = $request->input('restaurant_id');

        $data = $request->validate([
            'target_monthly_revenue' => ['required', 'numeric', 'min:0'],
            'target_profit_margin'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'avg_ticket_goal'        => ['nullable', 'numeric', 'min:0'],
        ]);

        $goal = FinancialGoal::updateOrCreate(
            ['restaurant_id' => $restaurantId],
            $data
        );

        return response()->json($goal);
    }
}