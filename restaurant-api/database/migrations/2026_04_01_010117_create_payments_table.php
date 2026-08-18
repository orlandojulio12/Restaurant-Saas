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
       Schema::create('payments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('restaurant_id')->constrained('restaurants');
    $table->foreignId('order_id')->constrained('orders');
    $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
    $table->enum('method', ['cash','card','nequi','daviplata','bancolombia','transfer','other'])->default('cash');
    $table->decimal('amount', 12, 2);
    $table->decimal('change_amount', 12, 2)->default(0);
    $table->string('reference', 100)->nullable();
    $table->enum('status', ['pending','completed','refunded','failed'])->default('completed');
    $table->string('notes', 255)->nullable();
    $table->timestamps();
    $table->index(['restaurant_id', 'created_at']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
