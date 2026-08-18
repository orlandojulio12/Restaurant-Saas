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
        Schema::create('subscriptions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
    $table->foreignId('plan_id')->constrained('plans');
    $table->enum('status', ['trialing','active','past_due','cancelled','expired'])->default('trialing');
    $table->enum('billing_cycle', ['monthly','yearly'])->default('monthly');
    $table->dateTime('current_period_start');
    $table->dateTime('current_period_end');
    $table->dateTime('cancelled_at')->nullable();
    $table->string('payment_reference', 255)->nullable();
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
