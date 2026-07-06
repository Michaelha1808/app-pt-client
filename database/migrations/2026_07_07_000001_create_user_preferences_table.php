<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['allergy', 'dislike', 'like', 'diet', 'habit']);
            $table->string('value', 100);            // đã normalize: lowercase, bỏ dấu — dùng match/unique
            $table->string('label', 100);            // hiển thị gốc: "Tôm", "Ăn chay trường"
            $table->enum('source', ['chat', 'manual', 'inferred'])->default('manual');
            $table->timestamp('last_confirmed_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'value']);    // 1 nguyên liệu chỉ mang 1 "thái độ" (like/dislike/allergy)
            $table->index(['user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
