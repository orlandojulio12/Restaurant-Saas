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
        Schema::create('order_item_additionals', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
    $table->foreignId('additional_id')->constrained('additionals');
    $table->string('additional_name', 100);
    $table->decimal('extra_price', 10, 2)->default(0);
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_item_additionals');
    }
};
