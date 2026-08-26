<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La carta pública es lo único que el comensal ve del restaurante, y hasta
 * ahora todas se veían iguales: el mismo verde del panel, con el logo reducido
 * a un cuadro de 44 píxeles en la esquina.
 *
 * `brand_color` es un hex del que el menú deriva su escala completa en tiempo
 * de render — la paleta ya son variables CSS, así que recolorear no toca ni una
 * clase. `cover_url` es la foto de cabecera sobre la que va el logo.
 *
 * Los dos son opcionales: sin ellos la carta sigue saliendo con la paleta por
 * defecto, que es lo que debe pasar mientras el restaurante no configure nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('brand_color', 7)->nullable()->after('logo_url');
            $table->string('cover_url', 500)->nullable()->after('brand_color');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['brand_color', 'cover_url']);
        });
    }
};
