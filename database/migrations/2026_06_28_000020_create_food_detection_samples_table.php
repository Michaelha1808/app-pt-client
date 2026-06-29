<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bảng thu thập dữ liệu nhận diện món ăn để cải thiện model (few-shot / fine-tune sau này).
     * Mỗi lần `detect` tạo 1 bản ghi (ai_dishes). Khi user xác nhận & sửa → cập nhật corrected_dishes.
     */
    public function up(): void
    {
        Schema::create('food_detection_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('input_type', 10);              // 'image' | 'text'
            $table->string('image_path')->nullable();      // path trên disk private (nullable nếu input text)
            $table->text('text_input')->nullable();
            $table->string('model')->nullable();           // model Gemini đã sinh ra ai_dishes
            $table->json('ai_dishes');                     // AI đoán (raw, đã normalize)
            $table->json('corrected_dishes')->nullable();  // kết quả user chốt (sau khi sửa tên/calo/số lượng/bỏ chọn)
            $table->boolean('has_correction')->default(false); // có khác biệt giữa AI và user không
            $table->boolean('saved')->default(false);          // user có thực sự ghi nhật ký không
            $table->timestamps();

            $table->index('created_at');
            $table->index('has_correction');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_detection_samples');
    }
};
