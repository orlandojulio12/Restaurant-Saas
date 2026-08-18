<?php

namespace App\Console\Commands;

use App\Jobs\GenerateDailySummary;
use App\Models\Restaurant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateDailySummaries extends Command
{
    protected $signature = 'summaries:generate
                            {date? : Fecha YYYY-MM-DD. Por defecto: ayer, en la zona horaria de cada restaurante}
                            {--restaurant= : Procesar solo este restaurant_id}
                            {--sync : Ejecutar en el momento en vez de encolar}';

    protected $description = 'Genera el resumen diario de ventas de los restaurantes activos.';

    public function handle(): int
    {
        $date = $this->argument('date');

        if ($date !== null && !$this->isValidDate($date)) {
            $this->error("Formato de fecha inválido: {$date}. Use YYYY-MM-DD.");

            return self::FAILURE;
        }

        $restaurants = Restaurant::query()
            ->where('is_active', true)
            ->when($this->option('restaurant'), fn($q, $id) => $q->where('id', $id))
            ->get(['id', 'name', 'timezone']);

        if ($restaurants->isEmpty()) {
            $this->info('No hay restaurantes activos que procesar.');

            return self::SUCCESS;
        }

        foreach ($restaurants as $restaurant) {
            // Sin fecha explícita, "ayer" se calcula en la zona horaria del restaurante.
            $targetDate = $date ?? Carbon::now($restaurant->timezone ?: 'UTC')
                ->subDay()
                ->toDateString();

            $job = new GenerateDailySummary($restaurant->id, $targetDate);

            $this->option('sync')
                ? dispatch_sync($job)
                : dispatch($job);

            $this->line("  {$restaurant->name} → {$targetDate}");
        }

        $verb = $this->option('sync') ? 'Procesados' : 'Encolados';
        $this->info("{$verb} {$restaurants->count()} resumen(es) diario(s).");

        return self::SUCCESS;
    }

    private function isValidDate(string $date): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            && Carbon::hasFormat($date, 'Y-m-d');
    }
}
