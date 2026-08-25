<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Breakdown nguyên liệu đọc trực tiếp từ ảnh "Thành phần món ăn" của VDD (qua
 * Gemini Vision — xem BackfillDishGrams). Thông tin mô tả (tên/gram/kcal thô
 * theo đúng ảnh gốc) — KHÔNG liên kết vdd_food_composition vì tên nguyên liệu
 * tự do, không chuẩn hoá theo mã VDD. Dùng để hiển thị "món gồm những gì" và
 * làm căn cứ cho dishes.reference_grams; không dùng để tính lại calo (đã có
 * calo tổng chuẩn trong `dishes` rồi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dish_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dish_id')->constrained('dishes')->cascadeOnDelete();
            $table->string('name', 150);
            $table->decimal('grams', 8, 1);
            $table->decimal('kcal', 8, 1)->nullable();
            $table->unsignedTinyInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dish_ingredients');
    }
};
