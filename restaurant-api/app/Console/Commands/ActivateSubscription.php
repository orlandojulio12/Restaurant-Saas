<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\Restaurant;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class ActivateSubscription extends Command
{
    protected $signature = 'subscription:activate
        {restaurant : slug o id del restaurante}
        {plan : nombre del plan (free, basic, pro)}
        {--cycle=monthly : monthly | yearly}
        {--periods=1 : cuántos ciclos se pagaron}
        {--reference= : referencia del pago (nº de transferencia, Nequi…)}';

    protected $description = 'Registra un pago recibido y activa el plan del restaurante';

    public function handle(SubscriptionService $subscriptions): int
    {
        $restaurant = $this->buscarRestaurante($this->argument('restaurant'));

        if (!$restaurant) {
            $this->error("No existe el restaurante «{$this->argument('restaurant')}».");

            return self::FAILURE;
        }

        $plan = Plan::where('name', $this->argument('plan'))->first();

        if (!$plan) {
            $this->error("No existe el plan «{$this->argument('plan')}».");
            $this->line('Disponibles: ' . Plan::pluck('name')->implode(', '));

            return self::FAILURE;
        }

        $cycle = $this->option('cycle');

        if (!in_array($cycle, ['monthly', 'yearly'], true)) {
            $this->error('El ciclo debe ser monthly o yearly.');

            return self::FAILURE;
        }

        $periods = max(1, (int) $this->option('periods'));
        $importe = $cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly;

        $this->line('');
        $this->line("  Restaurante : {$restaurant->name} ({$restaurant->slug})");
        $this->line("  Plan actual : {$restaurant->plan?->name}");
        $this->line("  Plan nuevo  : {$plan->name} — {$plan->display_name}");
        $this->line('  Periodos    : ' . $periods . ' × ' . ($cycle === 'yearly' ? 'año' : 'mes'));
        $this->line('  Importe     : ' . number_format((float) $importe * $periods, 0, ',', '.') . ' ' . $restaurant->currency);
        $this->line('');

        if (!$this->option('no-interaction') && !$this->confirm('¿Registrar el pago y activar?', true)) {
            $this->line('Cancelado.');

            return self::SUCCESS;
        }

        $suscripcion = $subscriptions->activate(
            restaurant: $restaurant,
            plan:       $plan,
            cycle:      $cycle,
            periods:    $periods,
            reference:  $this->option('reference'),
        );

        $this->info("Activado hasta el {$suscripcion->current_period_end->toDayDateTimeString()}.");

        if ($suscripcion->current_period_start->gt(now()->addDay())) {
            $this->line('  El periodo encadena con el anterior: no se pierden los días ya pagados.');
        }

        return self::SUCCESS;
    }

    private function buscarRestaurante(string $referencia): ?Restaurant
    {
        return Restaurant::with('plan')
            ->when(
                is_numeric($referencia),
                fn($q) => $q->where('id', $referencia),
                fn($q) => $q->where('slug', $referencia),
            )
            ->first();
    }
}
