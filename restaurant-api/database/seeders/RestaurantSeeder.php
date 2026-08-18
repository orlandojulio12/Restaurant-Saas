<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Restaurant;
use App\Models\RestaurantSetting;
use Illuminate\Database\Seeder;

class RestaurantSeeder extends Seeder
{
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
                'city'             => 'Bogotá',
                'currency'         => 'COP',
                'timezone'         => 'America/Bogota',
                'is_active'        => true,
            ]
        );

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
}
