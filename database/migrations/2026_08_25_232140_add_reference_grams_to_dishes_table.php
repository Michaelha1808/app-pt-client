<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tổng khối lượng (g) mà `calories`/macro hiện tại tương ứng — đọc từ bảng
 * "Thành phần" trong ảnh minh hoạ món ăn của VDD (xem BackfillDishGrams).
 * Null = chưa backfill được (ảnh không có bảng thành phần, hoặc món DEFENSE cũ).
 * Dùng làm mẫu số khi quy đổi khối lượng AI ước tính từ ảnh user chụp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dishes', function (Blueprint $table) {
            $table->decimal('reference_grams', 8, 1)->nullable()->after('sodium');
        });
    }

    public function down(): void
    {
        Schema::table('dishes', function (Blueprint $table) {
            $table->dropColumn('reference_grams');
        });
    }
};
