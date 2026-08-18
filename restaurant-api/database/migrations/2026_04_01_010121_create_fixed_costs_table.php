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
        Schema::create('fixed_costs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
    $table->string('name', 150);
    $table->decimal('amount', 12, 2);
    $table->enum('category', ['rent','utilities','staff','supplies','marketing','other'])->default('other');
    $table->enum('frequency', ['daily','weekly','monthly'])->default('monthly');
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fixed_costs');
    }
};
