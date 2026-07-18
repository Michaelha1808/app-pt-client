<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lời khuyên dinh dưỡng AI sinh ra khi phân tích món ăn → lưu lại để user xem lại
     * phần "phân tích" trong Lịch sử (trước đây stream xong là mất).
     */
    public function up(): void
    {
        Schema::table('meal_logs', function (Blueprint $table) {
            $table->text('ai_advice')->nullable()->after('sodium');
        });
    }

    public function down(): void
    {
        Schema::table('meal_logs', function (Blueprint $table) {
            $table->dropColumn('ai_advice');
        });
    }
};
