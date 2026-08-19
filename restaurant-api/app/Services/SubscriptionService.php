<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Restaurant;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Suscripciones con cobro manual.
 *
 * No hay pasarela: el restaurante paga por fuera (transferencia, Nequi) y se
 * registra aquí el pago con `php artisan subscription:activate`. El plan del
 * restaurante es la fuente de verdad de límites y funciones; la suscripción es
 * el registro comercial de qué se pagó, cuándo y hasta cuándo.
 */
class SubscriptionService
{
    /** Plan al que cae un restaurante cuando se le vence lo pagado. */
    public const PLAN_POR_DEFECTO = 'free';

    /**
     * Registra un pago y deja el plan activo hasta el fin del periodo.
     *
     * Si ya tiene una suscripción vigente, el periodo nuevo **encadena** con la
     * anterior en vez de empezar hoy: renovar antes de tiempo no debe costarle
     * al cliente los días que aún no ha consumido.
     */
    public function activate(
        Restaurant $restaurant,
        Plan $plan,
        string $cycle = 'monthly',
        int $periods = 1,
        ?string $reference = null,
    ): Subscription {
        return DB::transaction(function () use ($restaurant, $plan, $cycle, $periods, $reference) {
            $vigente = $this->vigente($restaurant);

            $inicio = $vigente && $vigente->plan_id === $plan->id
                ? $vigente->current_period_end->copy()
                : $restaurant->localNow();

            $fin = $inicio->copy();

            for ($i = 0; $i < $periods; $i++) {
                $cycle === 'yearly' ? $fin->addYear() : $fin->addMonthNoOverflow();
            }

            // Hasta el final del día local: que no venza a las 3 de la mañana.
            $fin->endOfDay();

            // Cada activación deja su propia fila: así queda el historial de
            // pagos recibidos, que es justo lo que hace falta cobrando a mano.
            $suscripcion = Subscription::create([
                'restaurant_id'        => $restaurant->id,
                'plan_id'              => $plan->id,
                'status'               => 'active',
                'billing_cycle'        => $cycle,
                'current_period_start' => $inicio,
                'current_period_end'   => $fin,
                'payment_reference'    => $reference,
            ]);

            $restaurant->update(['plan_id' => $plan->id]);

            return $suscripcion;
        });
    }

    /**
     * Da de baja lo vigente y devuelve el restaurante al plan gratuito.
     */
    public function cancel(Restaurant $restaurant): ?Subscription
    {
        $vigente = $this->vigente($restaurant);

        if (!$vigente) {
            return null;
        }

        return DB::transaction(function () use ($restaurant, $vigente) {
            $vigente->update([
                'status'       => 'cancelled',
                'cancelled_at' => now(),
            ]);

            $this->degradar($restaurant);

            return $vigente;
        });
    }

    /**
     * Vence lo que ya pasó de fecha y devuelve esos restaurantes al plan
     * gratuito. Lo ejecuta a diario `subscriptions:expire`.
     *
     * @return array{expiradas: int, restaurantes: string[]}
     */
    public function expireOverdue(): array
    {
        $vencidas = Subscription::with('restaurant')
            ->whereIn('status', ['active', 'trialing'])
            ->where('current_period_end', '<', now())
            ->get();

        $degradados = [];

        foreach ($vencidas as $suscripcion) {
            DB::transaction(function () use ($suscripcion, &$degradados) {
                $suscripcion->update(['status' => 'expired']);

                $restaurant = $suscripcion->restaurant;

                if (!$restaurant) {
                    return;
                }

                // Solo se degrada si no tiene otra suscripción todavía vigente:
                // puede haber renovado y encadenado un periodo nuevo.
                if ($this->vigente($restaurant)) {
                    return;
                }

                $this->degradar($restaurant);
                $degradados[] = $restaurant->slug;
            });
        }

        if ($degradados) {
            Log::info('Suscripciones vencidas.', ['restaurantes' => $degradados]);
        }

        return ['expiradas' => $vencidas->count(), 'restaurantes' => $degradados];
    }

    /**
     * Suscripción que cubre el momento actual, si la hay.
     */
    public function vigente(Restaurant $restaurant): ?Subscription
    {
        return Subscription::where('restaurant_id', $restaurant->id)
            ->whereIn('status', ['active', 'trialing'])
            ->where('current_period_end', '>=', now())
            ->latest('current_period_end')
            ->first();
    }

    private function degradar(Restaurant $restaurant): void
    {
        $gratuito = Plan::where('name', self::PLAN_POR_DEFECTO)->first();

        if ($gratuito) {
            $restaurant->update(['plan_id' => $gratuito->id]);
        }
    }
}
