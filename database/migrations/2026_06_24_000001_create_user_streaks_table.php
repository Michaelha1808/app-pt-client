<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_streaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('current_streak')->default(0);
            $table->unsignedSmallInteger('best_streak')->default(0);
            $table->date('last_activity_date')->nullable();       // ngày cuối được tính vào streak
            $table->unsignedTinyInteger('freeze_tokens')->default(0); // max 3
            $table->date('freeze_last_used_date')->nullable();    // ngày được bảo vệ bởi freeze
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_streaks');
    }
};
