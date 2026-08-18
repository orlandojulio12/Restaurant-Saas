<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('email', 150);
            $table->string('password', 255);
            $table->enum('role', ['admin', 'waiter', 'kitchen', 'cashier'])->default('waiter');
            $table->string('avatar_url', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamps();

            $table->unique(['email', 'restaurant_id']);
            $table->index(['restaurant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
