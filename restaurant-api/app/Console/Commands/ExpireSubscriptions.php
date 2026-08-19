<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Vence las suscripciones pasadas de fecha y devuelve esos restaurantes al plan gratuito';

    public function handle(SubscriptionService $subscriptions): int
    {
        $resultado = $subscriptions->expireOverdue();

        if ($resultado['expiradas'] === 0) {
            $this->info('No hay suscripciones vencidas.');

            return self::SUCCESS;
        }

        $this->info("Vencidas: {$resultado['expiradas']}.");

        foreach ($resultado['restaurantes'] as $slug) {
            $this->line("  {$slug} → plan gratuito");
        }

        return self::SUCCESS;
    }
}
