<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('scope', ['daily', 'monthly']);
            $table->date('target_date');           // daily: ngày áp dụng; monthly: ngày 1 của tháng
            $table->json('plan');                  // structured MealPlan
            $table->text('reasoning')->nullable(); // narrative đã stream
            $table->json('context_snapshot');      // BMR/TDEE/avg/adherence lúc tạo
            $table->string('data_hash', 40);       // hash input → phát hiện stale
            $table->timestamps();

            $table->unique(['user_id', 'scope', 'target_date']);
            $table->index(['user_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plans');
    }
};
