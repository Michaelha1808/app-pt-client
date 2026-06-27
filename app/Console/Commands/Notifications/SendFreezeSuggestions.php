<?php

namespace App\Console\Commands\Notifications;

use App\Console\Commands\Notifications\Concerns\DispatchesUserPush;
use App\Models\NotificationLog;
use App\Models\UserStreak;
use App\Services\FcmService;
use Illuminate\Console\Command;

class SendFreezeSuggestions extends Command
{
    use DispatchesUserPush;

    protected $signature   = 'notify:streak-freeze-remind';
    protected $description = 'Nhắc user dùng freeze token khi bỏ lỡ hôm qua và vẫn còn token';

    public function handle(FcmService $fcm): void
    {
        $today      = now()->toDateString();
        $twoDaysAgo = now()->subDays(2)->toDateString();
        $yesterday  = now()->subDay()->toDateString();

        // Users bỏ lỡ đúng hôm qua, còn freeze token, chưa dùng freeze cho hôm qua
        $streaks = UserStreak::where('current_streak', '>', 0)
            ->where('freeze_tokens', '>', 0)
            ->whereDate('last_activity_date', $twoDaysAgo)
            ->where(fn ($q) => $q->whereNull('freeze_last_used_date')
                                 ->orWhereDate('freeze_last_used_date', '!=', $yesterday))
            ->with(['user.notificationSubscriptions'])
            ->get();

        $this->info("[notify:streak-freeze-remind] {$streaks->count()} users to remind");

        foreach ($streaks as $streak) {
            $user = $streak->user;
            if (!$user) continue;

            $alreadySent = NotificationLog::where('user_id', $user->id)
                ->where('type', 'streak_freeze_remind')
                ->whereDate('created_at', $today)
                ->exists();

            if ($alreadySent) continue;

            $title = "💔 Ôi không! Chuỗi {$streak->current_streak} ngày bị gián đoạn";
            $body  = "Bạn có {$streak->freeze_tokens} Freeze Token. Dùng ngay để cứu chuỗi! ❄️";

            $this->dispatchPush($fcm, $user, [
                'type'  => 'streak_freeze_remind',
                'title' => $title,
                'body'  => $body,
                'url'   => '/home',
            ], ['action' => 'freeze']);
        }
    }
}
