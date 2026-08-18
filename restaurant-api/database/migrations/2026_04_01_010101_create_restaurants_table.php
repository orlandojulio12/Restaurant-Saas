<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->string('phone', 20)->nullable();
            $table->string('whatsapp_number', 20)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 60)->default('CO');
            $table->string('logo_url', 500)->nullable();
            $table->string('currency', 10)->default('COP');
            $table->string('timezone', 50)->default('America/Bogota');
            $table->boolean('is_active')->default(true);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurants');
    }
};
