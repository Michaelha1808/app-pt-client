<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thêm activity_level (mức vận động) vào users để tính TDEE đúng theo PAL
 * WHO/FAO 2001 thay vì hardcode nhân 1.375 (light) cho mọi user.
 *
 * Enum: sedentary | light | moderate | active | very_active — xem
 * App\Support\NutritionStandard::PAL cho hệ số nhân.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('activity_level', 20)->default('light')->after('gender');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('activity_level');
        });
    }
};
