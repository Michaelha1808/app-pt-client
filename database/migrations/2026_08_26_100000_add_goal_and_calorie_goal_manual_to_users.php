<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist mục tiêu (lose/maintain/gain) và cờ đánh dấu user đã chỉnh calorie_goal thủ công.
 *
 * Trước đây `goal` chỉ tồn tại trong step 3 của Register để tính calorie_goal ban đầu rồi vứt,
 * còn calorie_goal thì user muốn đổi phải bấm ± ở profile/Edit. Giờ để tự đồng bộ khi cân
 * nặng đổi (WeightService::logWeight) mình cần biết ý định gốc + biết user có chốt tay không.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('goal', 20)->nullable()->after('activity_level');
            $table->boolean('calorie_goal_manual')->default(false)->after('calorie_goal');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['goal', 'calorie_goal_manual']);
        });
    }
};
