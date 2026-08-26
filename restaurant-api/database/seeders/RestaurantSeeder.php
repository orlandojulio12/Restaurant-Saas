<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Restaurant;
use App\Models\RestaurantSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class RestaurantSeeder extends Seeder
{
    /**
     * Color de marca del restaurante de demostración.
     *
     * De aquí sale la paleta entera de la carta pública (`lib/paleta.ts` en el
     * frontend), así que conviene que el sembrado traiga uno: sin él la demo
     * sale con el verde por defecto del producto y no se ve que la carta es del
     * restaurante, que es justo lo que hay que enseñar.
     *
     * Ladrillo hondo, de la carrocería de una chiva. Da 9.52 de contraste con
     * blanco —muy por encima del 4.5 de AA— y enmarca la banda de comida en vez
     * de fundirse con sus naranjas.
     */
    private const COLOR_MARCA = '#7C2A1E';

    public function run(): void
    {
        $plan = Plan::where('name', 'pro')->firstOrFail();

        $restaurant = Restaurant::updateOrCreate(
            ['slug' => 'el-rincon-de-prueba'],
            [
                'plan_id'          => $plan->id,
                'name'             => 'El Rincón de Prueba',
                'slug'             => 'el-rincon-de-prueba',
                'phone'            => '3001234567',
                'whatsapp_number'  => '573001234567',
                'address'          => 'Calle 12 #4-30, La Candelaria',
                'city'             => 'Bogotá',
                'brand_color'      => self::COLOR_MARCA,
                'currency'         => 'COP',
                'timezone'         => 'America/Bogota',
                'is_active'        => true,
            ]
        );

        $this->ponerPortada($restaurant);

        $settings = [
            'mode'          => 'tables',
            'tax_percent'   => '0',
            'print_kitchen' => '1',
            'notify_sound'  => '1',
        ];

        foreach ($settings as $key => $value) {
            RestaurantSetting::updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'key_name' => $key],
                ['value' => $value]
            );
        }
    }

    /**
     * Publica la foto de cabecera de la carta.
     *
     * Igual que las fotos de producto: viaja en `database/seeders/fotos/` y se
     * copia al disco público en cada `migrate:fresh --seed`, porque `storage/`
     * está fuera del repositorio.
     */
    private function ponerPortada(Restaurant $restaurant): void
    {
        $origen = database_path('seeders/fotos/portada.jpg');

        if (!is_file($origen)) {
            return;
        }

        $ruta = "covers/{$restaurant->id}/portada.jpg";

        Storage::disk('public')->put($ruta, file_get_contents($origen));

        $restaurant->update(['cover_url' => Storage::disk('public')->url($ruta)]);
    }
}
