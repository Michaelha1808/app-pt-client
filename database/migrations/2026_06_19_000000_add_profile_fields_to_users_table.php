<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_url', 500)->nullable()->after('email');
            $table->smallInteger('birth_year')->nullable()->after('avatar_url');
            $table->enum('gender', ['male', 'female', 'other'])->nullable()->after('birth_year');
            $table->decimal('height_cm', 5, 1)->nullable()->after('gender');
            $table->decimal('weight_kg', 5, 1)->nullable()->after('height_cm');
            $table->smallInteger('calorie_goal')->default(2000)->after('weight_kg');
            $table->time('morning_notify')->default('07:00:00')->after('calorie_goal');
            $table->time('evening_notify')->default('21:00:00')->after('morning_notify');
            $table->smallInteger('calorie_streak')->default(0)->after('evening_notify');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'avatar_url', 'birth_year', 'gender', 'height_cm', 'weight_kg',
                'calorie_goal', 'morning_notify', 'evening_notify', 'calorie_streak',
            ]);
        });
    }
};
