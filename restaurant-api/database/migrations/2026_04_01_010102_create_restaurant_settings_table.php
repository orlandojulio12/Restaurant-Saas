<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->string('key_name', 100);
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['restaurant_id', 'key_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_settings');
    }
};
