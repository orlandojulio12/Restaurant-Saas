<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $restaurant = Restaurant::where('slug', 'el-rincon-de-prueba')->firstOrFail();

        $users = [
            [
                'name'     => 'Admin Principal',
                'email'    => 'admin@test.com',
                'role'     => 'admin',
            ],
            [
                'name'     => 'Juan Mozo',
                'email'    => 'mozo@test.com',
                'role'     => 'waiter',
            ],
            [
                'name'     => 'Chef Carlos',
                'email'    => 'cocina@test.com',
                'role'     => 'kitchen',
            ],
            [
                'name'     => 'María Cajero',
                'email'    => 'caja@test.com',
                'role'     => 'cashier',
            ],
        ];

        foreach ($users as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'restaurant_id' => $restaurant->id,
                    'name'          => $data['name'],
                    'password'      => Hash::make('password'),
                    'role'          => $data['role'],
                    'is_active'     => true,
                ]
            );
        }
    }
}
