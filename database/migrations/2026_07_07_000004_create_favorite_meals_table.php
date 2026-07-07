<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorite_meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('food_name');
            $table->string('serving')->nullable();
            $table->integer('calories');
            $table->integer('protein')->default(0);
            $table->integer('carbs')->default(0);
            $table->integer('fat')->default(0);
            $table->integer('sodium')->default(0);
            $table->string('image_path')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'food_name', 'serving']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorite_meals');
    }
};
