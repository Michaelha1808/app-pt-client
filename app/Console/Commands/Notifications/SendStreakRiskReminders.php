<?php

namespace App\Console\Commands\Notifications;

use App\Models\NotificationLog;
use App\Models\NotificationSubscription;
use App\Models\UserStreak;
use App\Services\FcmService;
use Illuminate\Console\Command;

class SendStreakRiskReminders extends Command
{
    protected $signature   = 'notify:streak-risk';
    protected $description = 'Nhắc user chưa log bữa ăn hôm nay vào lúc 21:00 để giữ streak';

    public function handle(FcmService $fcm): void
    {
        $now = now(config('app.timezone'))->format('H:i');

        // Chỉ gửi ở khung 21:00
        if ($now !== '21:00') return;

        $today = now()->toDateString();

        // Lấy users có streak > 0, chưa log hôm nay, chưa nhận reminder hôm nay
        $streaks = UserStreak::where('current_streak', '>', 0)
            ->where(fn ($q) => $q->whereNull('last_activity_date')
                                 ->orWhereDate('last_activity_date', '<', $today))
            ->with(['user.notificationSubscriptions'])
            ->get();

        $this->info("[notify:streak-risk] {$now} — {$streaks->count()} users at risk");

        foreach ($streaks as $streak) {
            $user = $streak->user;
            if (!$user) continue;

            // Kiểm tra chưa gửi hôm nay
            $alreadySent = NotificationLog::where('user_id', $user->id)
                ->where('type', 'streak_risk')
                ->whereDate('created_at', $today)
                ->exists();

            if ($alreadySent) continue;

            $title  = "🥑 Chuỗi {$streak->current_streak} ngày sắp bị gián đoạn!";
            $body   = 'Bạn chưa log bữa ăn nào hôm nay. Còn vài tiếng để giữ chuỗi nhé!';
            $tokens = $user->notificationSubscriptions->pluck('fcm_token')->toArray();

            $invalid = $fcm->sendMulticast($tokens, $title, $body, ['url' => '/home']);
            $this->removeInvalidTokens($invalid);

            NotificationLog::create([
                'user_id' => $user->id,
                'type'    => 'streak_risk',
                'title'   => $title,
                'body'    => $body,
                'url'     => '/home',
            ]);
        }
    }

    private function removeInvalidTokens(array $tokens): void
    {
        if (!empty($tokens)) {
            NotificationSubscription::whereIn('fcm_token', $tokens)->delete();
        }
    }
}
