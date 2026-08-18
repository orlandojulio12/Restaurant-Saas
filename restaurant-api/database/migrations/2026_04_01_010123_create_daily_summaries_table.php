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
       Schema::create('daily_summaries', function (Blueprint $table) {
    $table->id();
    $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
    $table->date('date');
    $table->unsignedInteger('total_orders')->default(0);
    $table->decimal('total_sales', 14, 2)->default(0);
    $table->decimal('total_cost', 14, 2)->default(0);
    $table->decimal('gross_profit', 14, 2)->default(0);
    $table->decimal('avg_ticket', 10, 2)->default(0);
    $table->unsignedInteger('orders_dine_in')->default(0);
    $table->unsignedInteger('orders_delivery')->default(0);
    $table->unsignedInteger('orders_counter')->default(0);
    $table->foreignId('top_product_id')->nullable()->constrained('products')->nullOnDelete();
    $table->timestamps();
    $table->unique(['restaurant_id', 'date']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_summaries');
    }
};
