<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Công thức nguyên liệu (recipes) cho từng món trong thư viện `dishes`.
 * Mỗi món có N dòng — mỗi dòng 1 nguyên liệu từ vdd_food_composition,
 * kèm khối lượng gram/khẩu phần.
 *
 * Dùng để tính lại calo/macro món ăn từ FCT VDD thay vì hardcode.
 * VD: Phở bò = 200g bánh phở + 80g thịt bò + 15g hành + 500ml nước dùng.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dish_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dish_id')->constrained('dishes')->cascadeOnDelete();
            $table->foreignId('vdd_food_id')->constrained('vdd_food_composition');
            $table->decimal('grams_per_serving', 8, 2);
            $table->string('note', 100)->nullable();
            $table->unsignedTinyInteger('order')->default(0);
            $table->timestamps();

            $table->unique(['dish_id', 'vdd_food_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dish_recipes');
    }
};
