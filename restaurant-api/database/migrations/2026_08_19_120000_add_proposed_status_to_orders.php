<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estado previo a cocina para los pedidos que arma el propio comensal desde el
 * QR de la mesa.
 *
 * Sin él, lo que teclea un cliente entra directo a la cocina sin que nadie lo
 * mire. Con él, el pedido llega al mesero como propuesta: cuando pasa por la
 * mesa lo confirma —y ahí sí baja a cocina— o lo descarta.
 *
 * La columna `confirmed_at` ya existía en la tabla desde el principio, sin que
 * nada la escribiera: este es el paso que le faltaba.
 */
return new class extends Migration
{
    private const ESTADOS = [
        'proposed',
        'pending',
        'preparing',
        'ready',
        'on_the_way',
        'delivered',
        'paid',
        'closed',
        'cancelled',
    ];

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', self::ESTADOS)->default('pending')->change();
        });
    }

    public function down(): void
    {
        // Las propuestas sin confirmar no caben en el enum anterior; se
        // descartan, que es lo que significan: nunca llegaron a cocina.
        DB::table('orders')->where('status', 'proposed')->update(['status' => 'cancelled']);

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', array_values(array_diff(self::ESTADOS, ['proposed'])))
                ->default('pending')
                ->change();
        });
    }
};
