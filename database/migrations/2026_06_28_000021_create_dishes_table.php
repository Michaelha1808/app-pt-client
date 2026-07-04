<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Thư viện món ăn chuẩn (nutrition DB). Dùng để "grounding": Gemini nhận diện tên món,
     * còn calo/macro lấy từ bảng này thay vì để AI đoán. Tên cũng được chuẩn hoá về canonical.
     */
    public function up(): void
    {
        Schema::create('dishes', function (Blueprint $table) {
            $table->id();
            $table->string('name');                         // tên canonical tiếng Việt (vd "Phở bò")
            $table->string('name_normalized')->index();     // không dấu, lowercase — để khớp
            $table->json('aliases')->nullable();             // tên gọi khác (vd ["pho", "phở bò tái"])
            $table->string('unit_type', 10)->default('portion'); // countable | portion
            $table->string('unit_label')->default('phần');  // chén/tô/đĩa/cái...
            $table->string('serving')->default('1 khẩu phần'); // mô tả 1 đơn vị chuẩn
            $table->integer('calories')->default(0);         // cho 1 đơn vị chuẩn
            $table->integer('protein')->default(0);
            $table->integer('carbs')->default(0);
            $table->integer('fat')->default(0);
            $table->integer('sodium')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dishes');
    }
};
