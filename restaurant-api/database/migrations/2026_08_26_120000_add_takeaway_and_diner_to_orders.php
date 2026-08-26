<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dos huecos que salieron al mirar el QR con varios comensales en una mesa.
 *
 * `takeaway` es el postre que la mesa 5 se lleva: no es un pedido de barra
 * —eso ya es `counter`— ni deja de pertenecer a la mesa, así que conserva su
 * `table_id` y entra en la misma cuenta. Mezclarlo con `counter` haría que los
 * reportes contaran como venta de mostrador algo que se consumió en salón.
 *
 * `diner_name` es quien pidió. Con tres móviles escaneando el mismo código, el
 * mesero necesita saber a quién le lleva cada plato; el comensal lo escribe una
 * vez y su navegador lo recuerda.
 */
return new class extends Migration
{
    private const TIPOS = ['dine_in', 'delivery', 'counter', 'takeaway'];

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('type', self::TIPOS)->change();
            $table->string('diner_name', 60)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        // Lo que se llevó la mesa se parece más a un consumo en salón que a una
        // venta de mostrador, así que al revertir vuelve a `dine_in`.
        DB::table('orders')->where('type', 'takeaway')->update(['type' => 'dine_in']);

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('type', array_values(array_diff(self::TIPOS, ['takeaway'])))->change();
            $table->dropColumn('diner_name');
        });
    }
};
