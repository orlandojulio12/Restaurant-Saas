<?php

namespace App\Http\Middleware;

use App\Models\Restaurant;
use App\Services\PlanService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra un módulo entero si el plan no lo incluye: plan.feature:inventory
 *
 * Va a nivel de ruta porque son módulos completos; los cupos numéricos
 * (max_products y compañía) se comprueban al crear, dentro del controlador.
 */
class EnsurePlanFeature
{
    private const NOMBRES = [
        'whatsapp'   => 'WhatsApp',
        'inventory'  => 'inventario',
        'reports'    => 'reportes',
        'financials' => 'finanzas',
    ];

    public function __construct(private readonly PlanService $plans) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $restaurant = Restaurant::with('plan')->find($request->input('restaurant_id'));

        if (!$restaurant) {
            return response()->json(['message' => 'Restaurante no encontrado.'], 404);
        }

        if (!$this->plans->allows($restaurant, $feature)) {
            return response()->json([
                'message' => 'Tu plan no incluye el módulo de ' . (self::NOMBRES[$feature] ?? $feature) . '.',
                'feature' => $feature,
            ], 403);
        }

        return $next($request);
    }
}
