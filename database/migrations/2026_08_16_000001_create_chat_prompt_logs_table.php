<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_prompt_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('user_message');
            $table->longText('final_prompt')->nullable();  // system prompt cá nhân hóa đã gửi Gemini (null nếu ngoài phạm vi)
            $table->longText('reply')->nullable();
            $table->string('model', 60)->nullable();
            $table->boolean('in_scope')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_prompt_logs');
    }
};
