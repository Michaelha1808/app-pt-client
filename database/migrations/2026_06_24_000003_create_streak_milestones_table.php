<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streak_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('days'); // 3, 7, 14, 30, 60, 100
            $table->timestamp('achieved_at');
            $table->timestamp('push_sent_at')->nullable();

            $table->unique(['user_id', 'days']); // mỗi milestone chỉ đạt 1 lần
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streak_milestones');
    }
};
