<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ảnh món ăn đã chụp (chỉ log từ ảnh mới có; nhập tay để NULL → FE dùng icon).
     */
    public function up(): void
    {
        Schema::table('meal_logs', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('serving');
        });
    }

    public function down(): void
    {
        Schema::table('meal_logs', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
