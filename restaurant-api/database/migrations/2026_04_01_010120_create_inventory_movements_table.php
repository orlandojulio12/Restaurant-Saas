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
       Schema::create('inventory_movements', function (Blueprint $table) {
    $table->id();
    $table->foreignId('restaurant_id')->constrained('restaurants');
    $table->foreignId('ingredient_id')->constrained('ingredients');
    $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->enum('type', ['in','out','adjustment','waste']);
    $table->decimal('quantity', 12, 3);
    $table->decimal('stock_before', 12, 3);
    $table->decimal('stock_after', 12, 3);
    $table->decimal('unit_cost', 12, 4)->default(0);
    $table->string('reason', 255)->nullable();
    $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
