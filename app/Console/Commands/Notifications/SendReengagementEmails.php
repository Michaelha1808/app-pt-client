<?php

namespace App\Console\Commands\Notifications;

use App\Mail\ReengagementMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendReengagementEmails extends Command
{
    protected $signature   = 'notify:reengagement';
    protected $description = 'Gửi email cho users không hoạt động lâu ngày (số ngày cấu hình trong Settings)';

    public function handle(\App\Services\SettingsService $settings): void
    {
        // Công tắc global trong Admin Settings — tắt là dừng toàn bộ, bất kể user pref.
        if ($settings->get('mail.reengagement_enabled', true) !== true) {
            $this->info('[notify:reengagement] Đã tắt trong Settings — bỏ qua.');
            return;
        }

        $days   = max(1, (int) $settings->get('notifications.reengagement_days', 7));
        $cutoff = now()->subDays($days);

        // Chỉ gửi 1 lần mỗi chu kỳ $days ngày — tránh spam
        $users = User::where('email_reengagement_enabled', true)
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_seen_at')
                  ->orWhere('last_seen_at', '<', $cutoff);
            })
            ->where(function ($q) use ($cutoff) {
                // Chưa được gửi email re-engagement trong vòng 7 ngày
                $q->whereNull('reengagement_sent_at')
                  ->orWhere('reengagement_sent_at', '<', $cutoff);
            })
            ->whereNotNull('email')
            ->get();

        $this->info("[notify:reengagement] {$users->count()} users to email");

        foreach ($users as $user) {
            try {
                Mail::to($user->email)->send(new ReengagementMail($user));
                $user->timestamps = false;
                $user->update(['reengagement_sent_at' => now()]);
                $this->line("  ✓ Sent to {$user->email}");
            } catch (\Throwable $e) {
                $this->warn("  ✗ Failed for {$user->email}: {$e->getMessage()}");
            }
        }
    }
}
