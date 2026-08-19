<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class ListSubscriptions extends Command
{
    protected $signature = 'subscription:list {--expiring= : solo los que vencen en N días o menos}';

    protected $description = 'Estado de todos los restaurantes: plan, vencimiento y último pago';

    public function handle(SubscriptionService $subscriptions): int
    {
        $porVencer = $this->option('expiring') !== null
            ? max(0, (int) $this->option('expiring'))
            : null;

        $filas = [];

        foreach (Restaurant::with('plan')->orderBy('name')->get() as $restaurant) {
            $vigente = $subscriptions->vigente($restaurant);
            $dias    = $vigente ? (int) now()->diffInDays($vigente->current_period_end, false) : null;

            if ($porVencer !== null && ($dias === null || $dias > $porVencer)) {
                continue;
            }

            $filas[] = [
                $restaurant->slug,
                $restaurant->plan?->name ?? '—',
                $vigente ? $vigente->current_period_end->toDateString() : '—',
                $dias === null ? 'sin suscripción' : "{$dias} d",
                $vigente?->payment_reference ?? '—',
            ];
        }

        if (!$filas) {
            $this->info('Nada que mostrar.');

            return self::SUCCESS;
        }

        $this->table(['Restaurante', 'Plan', 'Vence', 'Quedan', 'Referencia'], $filas);

        return self::SUCCESS;
    }
}
