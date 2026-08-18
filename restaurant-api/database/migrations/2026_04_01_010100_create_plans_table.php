<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 50)->unique();
            $table->string('display_name', 100);
            $table->smallInteger('max_tables')->default(2);
            $table->smallInteger('max_products')->default(20);
            $table->smallInteger('max_daily_orders')->default(20);
            $table->boolean('has_whatsapp')->default(false);
            $table->boolean('has_inventory')->default(false);
            $table->boolean('has_reports')->default(false);
            $table->boolean('has_financials')->default(false);
            $table->decimal('price_monthly', 10, 2)->default(0);
            $table->decimal('price_yearly', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
