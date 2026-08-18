<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\Zone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TableSeeder extends Seeder
{
    public function run(): void
    {
        $restaurant = Restaurant::where('slug', 'el-rincon-de-prueba')->firstOrFail();

        $zone = Zone::updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'name' => 'Salón Principal'],
            ['sort_order' => 1]
        );

        for ($i = 1; $i <= 8; $i++) {
            RestaurantTable::updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'number' => $i],
                [
                    'zone_id'  => $zone->id,
                    'capacity' => 4,
                    'qr_code'  => Str::uuid()->toString(),
                    'status'   => 'available',
                ]
            );
        }
    }
}
