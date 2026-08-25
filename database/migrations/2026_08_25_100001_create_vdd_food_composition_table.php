<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bảng Thành phần Thực phẩm Việt Nam (FCT) — Viện Dinh dưỡng 2007/2017.
 *
 * Nguồn: https://viendinhduong.vn/vi/cong-cu-va-tien-ich/gia-tri-dinh-duong
 * Cấu trúc theo cột đúng như bảng gốc: 100g phần ăn được (edible portion).
 * Dùng để cross-check calo món ăn AI ước tính và compute recipe (bảng
 * dish_recipes) — thay vì để AI tự đoán.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vdd_food_composition', function (Blueprint $table) {
            $table->id();
            // Mã số FCT VDD (5-7 chữ số). Giữ string vì bảng gốc có mã 1007014...
            $table->string('vdd_code', 20)->unique();
            $table->string('name_vi', 200);
            $table->string('name_en', 200)->nullable();
            $table->string('group_name', 100);
            // Bỏ dấu + lowercase để search không dấu (giống Dish::name_normalized)
            $table->string('name_normalized', 200)->index();

            // Giá trị chuẩn cho 100g phần ăn được
            $table->decimal('energy_kcal',  8, 2);
            $table->decimal('protein_g',    6, 2)->default(0);
            $table->decimal('fat_g',        6, 2)->default(0);
            $table->decimal('carbs_g',      6, 2)->default(0);
            $table->decimal('fiber_g',      6, 2)->default(0);
            $table->decimal('water_g',      6, 2)->nullable();

            // Vi chất chính — mg trừ vitamin A (µg)
            $table->decimal('calcium_mg',     8, 2)->default(0);
            $table->decimal('iron_mg',        6, 2)->default(0);
            $table->decimal('sodium_mg',      8, 2)->default(0);
            $table->decimal('potassium_mg',   8, 2)->default(0);
            $table->decimal('zinc_mg',        6, 2)->default(0);
            $table->decimal('vitamin_a_mcg',  8, 2)->default(0);
            $table->decimal('vitamin_c_mg',   6, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vdd_food_composition');
    }
};
