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
        Schema::create('financial_goals', function (Blueprint $table) {
    $table->id();
    $table->foreignId('restaurant_id')->unique()->constrained('restaurants')->cascadeOnDelete();
    $table->decimal('target_monthly_revenue', 14, 2)->default(0);
    $table->decimal('target_profit_margin', 5, 2)->default(30);
    $table->decimal('avg_ticket_goal', 10, 2)->default(0);
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_goals');
    }
};
