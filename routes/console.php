<?php

use App\Console\Commands\Notifications\SendEveningNotifications;
use App\Console\Commands\Notifications\SendFreezeSuggestions;
use App\Console\Commands\Notifications\SendMiddayNotifications;
use App\Console\Commands\Notifications\SendMorningNotifications;
use App\Console\Commands\Notifications\SendReengagementEmails;
use App\Console\Commands\Notifications\SendStreakRiskReminders;
use App\Jobs\RefreshExpiringTokensJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Thông báo đầu ngày — chạy mỗi phút, tự filter theo giờ từng user.
// withoutOverlapping: nếu một lần chạy vượt quá 60s (nhiều user trùng giờ) thì
// lần kế KHÔNG chồng lên — tránh gửi trùng và nghẽn.
Schedule::command(SendMorningNotifications::class)->everyMinute()->withoutOverlapping(5)->runInBackground();

// Thông báo giữa ngày — cố định 12:00 (Asia/Ho_Chi_Minh)
Schedule::command(SendMiddayNotifications::class)->dailyAt('12:00');

// Thông báo cuối ngày — chạy mỗi phút, tự filter theo giờ từng user
Schedule::command(SendEveningNotifications::class)->everyMinute()->withoutOverlapping(5)->runInBackground();

// Email re-engagement — chạy mỗi ngày lúc 09:00
Schedule::command(SendReengagementEmails::class)->dailyAt('09:00');

// Streak at-risk — chạy mỗi phút, tự filter theo 21:00 từng user
Schedule::command(SendStreakRiskReminders::class)->everyMinute()->withoutOverlapping(5)->runInBackground();

// Gợi ý freeze token — chạy mỗi ngày lúc 09:00
Schedule::command(SendFreezeSuggestions::class)->dailyAt('09:00');

// Health: refresh token provider sắp hết hạn (Strava ~6h) — chạy mỗi giờ
Schedule::job(new RefreshExpiringTokensJob())->hourly();
