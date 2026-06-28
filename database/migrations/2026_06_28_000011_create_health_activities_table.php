<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Buổi tập — CHUNG cho cả nguồn provider (Strava…) lẫn log thủ công (manual).
     * Manual: provider='manual', source='manual', external_id/health_connection_id/raw = NULL.
     * Dedup provider qua unique(provider, external_id) — manual không vướng vì external_id NULL
     * (Postgres cho phép nhiều NULL trong unique index).
     */
    public function up(): void
    {
        Schema::create('health_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('health_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');                       // strava | fitbit | garmin | manual
            $table->string('external_id')->nullable();        // activity id bên provider; NULL khi manual
            $table->string('source')->default('provider');    // provider | manual
            $table->string('type');                           // run / ride / swim / workout / walk ...
            $table->string('name')->nullable();
            $table->timestamp('started_at');
            $table->integer('duration_seconds');
            $table->integer('distance_meters')->nullable();
            $table->integer('calories')->nullable();          // dùng cộng vào ngày (manual: ước lượng MET)
            $table->json('raw')->nullable();                  // payload gốc provider
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
            $table->unique(['provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_activities');
    }
};
