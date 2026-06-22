<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('morning_notify_enabled')->default(true)->after('morning_notify');
            $table->boolean('midday_notify_enabled')->default(true)->after('morning_notify_enabled');
            $table->boolean('evening_notify_enabled')->default(false)->after('evening_notify');
            $table->boolean('email_reengagement_enabled')->default(true)->after('evening_notify_enabled');
            $table->timestamp('last_seen_at')->nullable()->after('email_reengagement_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'morning_notify_enabled',
                'midday_notify_enabled',
                'evening_notify_enabled',
                'email_reengagement_enabled',
                'last_seen_at',
            ]);
        });
    }
};
