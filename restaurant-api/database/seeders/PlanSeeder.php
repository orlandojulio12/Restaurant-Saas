<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name'             => 'free',
                'display_name'     => 'Gratis',
                'max_tables'       => 2,
                'max_products'     => 20,
                'max_daily_orders' => 20,
                'has_whatsapp'     => false,
                'has_inventory'    => false,
                'has_reports'      => false,
                'has_financials'   => false,
                'price_monthly'    => 0,
                'price_yearly'     => 0,
            ],
            [
                'name'             => 'basic',
                'display_name'     => 'Básico',
                'max_tables'       => 10,
                'max_products'     => 100,
                'max_daily_orders' => 0,
                'has_whatsapp'     => true,
                'has_inventory'    => false,
                'has_reports'      => true,
                'has_financials'   => false,
                'price_monthly'    => 49000,
                'price_yearly'     => 470000,
            ],
            [
                'name'             => 'pro',
                'display_name'     => 'Pro',
                'max_tables'       => 0,
                'max_products'     => 0,
                'max_daily_orders' => 0,
                'has_whatsapp'     => true,
                'has_inventory'    => true,
                'has_reports'      => true,
                'has_financials'   => true,
                'price_monthly'    => 99000,
                'price_yearly'     => 950000,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['name' => $plan['name']], $plan);
        }
    }
}
