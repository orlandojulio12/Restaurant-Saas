<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Resumen diario de ventas: alimenta daily_summaries, de donde leen los
// reportes y todo el módulo financiero. A las 04:00 UTC (23:00 en Bogotá)
// ya cerró la operación del día anterior en cualquier zona horaria de Colombia.
Schedule::command('summaries:generate')
    ->dailyAt('04:00')
    ->withoutOverlapping();

// Devuelve al plan gratuito a quien se le venció lo pagado. Va antes del
// resumen para que las cifras del día se calculen ya con el plan correcto.
Schedule::command('subscriptions:expire')
    ->dailyAt('03:30')
    ->withoutOverlapping();
