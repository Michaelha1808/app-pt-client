<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('url')->nullable();
            $table->json('segment')->nullable();              // bộ lọc phân khúc
            $table->unsignedInteger('audience_count')->default(0); // số user mục tiêu (lúc tạo)
            $table->unsignedInteger('sent_count')->default(0);     // số đã xử lý
            $table->unsignedInteger('push_count')->default(0);     // số thiết bị nhận push
            $table->string('status', 20)->default('queued')->index(); // queued|sending|done|failed
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_campaigns');
    }
};
