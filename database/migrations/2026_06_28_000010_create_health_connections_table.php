<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Một user có thể nối nhiều provider sức khoẻ (strava | fitbit | garmin).
     * Token lưu mã hoá at-rest qua cast 'encrypted' của model — KHÔNG bao giờ trả ra API.
     */
    public function up(): void
    {
        Schema::create('health_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');                       // strava | fitbit | garmin
            $table->string('provider_user_id')->nullable();   // athlete id bên provider
            $table->text('access_token')->nullable();         // ENCRYPTED (model cast)
            $table->text('refresh_token')->nullable();        // ENCRYPTED (model cast)
            $table->timestamp('token_expires_at')->nullable();
            $table->string('scopes')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('webhook_id')->nullable();         // id subscription bên provider
            $table->string('status')->default('active');      // active | revoked | error
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_connections');
    }
};
