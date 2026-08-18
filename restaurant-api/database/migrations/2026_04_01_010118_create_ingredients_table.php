<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
       Schema::create('ingredients', function (Blueprint $table) {
    $table->id();
    $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
    $table->string('name', 100);
    $table->string('unit', 20);
    $table->decimal('stock', 12, 3)->default(0);
    $table->decimal('min_stock', 12, 3)->default(0);
    $table->decimal('cost_per_unit', 12, 4)->default(0);
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
