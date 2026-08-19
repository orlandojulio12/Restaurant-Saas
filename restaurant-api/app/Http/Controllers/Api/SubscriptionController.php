<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * Estado de la suscripción del restaurante y su historial de pagos.
     *
     * No hay alta ni cobro por API: los pagos se reciben por fuera y se
     * registran con `php artisan subscription:activate`.
     */
    public function show(Request $request): JsonResponse
    {
        $restaurant = Restaurant::with('plan')->findOrFail($request->input('restaurant_id'));
        $vigente    = $this->subscriptions->vigente($restaurant);

        return response()->json([
            'plan' => [
                'name'           => $restaurant->plan->name,
                'display_name'   => $restaurant->plan->display_name,
                'price_monthly'  => (float) $restaurant->plan->price_monthly,
                'price_yearly'   => (float) $restaurant->plan->price_yearly,
                'max_tables'     => (int) $restaurant->plan->max_tables,
                'max_products'   => (int) $restaurant->plan->max_products,
                'max_daily_orders' => (int) $restaurant->plan->max_daily_orders,
                'has_whatsapp'   => (bool) $restaurant->plan->has_whatsapp,
                'has_inventory'  => (bool) $restaurant->plan->has_inventory,
                'has_reports'    => (bool) $restaurant->plan->has_reports,
                'has_financials' => (bool) $restaurant->plan->has_financials,
            ],
            'subscription' => $vigente ? [
                'status'        => $vigente->status,
                'billing_cycle' => $vigente->billing_cycle,
                'started_at'    => $vigente->current_period_start->toDateString(),
                'expires_at'    => $vigente->current_period_end->toDateString(),
                'days_left'     => max(0, (int) now()->diffInDays($vigente->current_period_end, false)),
            ] : null,
            // Sin suscripción vigente el restaurante está en el plan gratuito.
            'is_paid' => $vigente !== null,
            'history' => Subscription::where('restaurant_id', $restaurant->id)
                ->with('plan:id,name,display_name')
                ->latest('current_period_start')
                ->limit(24)
                ->get()
                ->map(fn(Subscription $s) => [
                    'plan'       => $s->plan?->name,
                    'status'     => $s->status,
                    'cycle'      => $s->billing_cycle,
                    'from'       => $s->current_period_start->toDateString(),
                    'to'         => $s->current_period_end->toDateString(),
                    'reference'  => $s->payment_reference,
                ]),
        ]);
    }
}
