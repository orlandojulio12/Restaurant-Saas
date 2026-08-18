<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El enum original solo admitía daily|weekly|monthly, pero FinancialController
 * ya normalizaba biweekly, quarterly y yearly: eran ramas muertas imposibles de
 * alcanzar. Se amplía el enum para que coincida con el cálculo.
 */
return new class extends Migration
{
    private const FRECUENCIAS = ['daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'yearly'];

    public function up(): void
    {
        Schema::table('fixed_costs', function (Blueprint $table) {
            $table->enum('frequency', self::FRECUENCIAS)->default('monthly')->change();
        });
    }

    public function down(): void
    {
        // Las frecuencias nuevas no caben en el enum original: se reducen a la
        // equivalente más cercana antes de restaurarlo.
        DB::table('fixed_costs')->whereIn('frequency', ['biweekly', 'quarterly', 'yearly'])
            ->update(['frequency' => 'monthly']);

        Schema::table('fixed_costs', function (Blueprint $table) {
            $table->enum('frequency', ['daily', 'weekly', 'monthly'])->default('monthly')->change();
        });
    }
};
