<?php

namespace App\Console\Commands\Notifications;

use App\Models\MealLog;
use App\Models\NotificationSubscription;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Console\Command;

class SendMiddayNotifications extends Command
{
    protected $signature   = 'notify:midday';
    protected $description = 'Gửi thông báo giữa ngày (12:00) kèm calo còn lại';

    public function handle(FcmService $fcm): void
    {
        $users = User::where('midday_notify_enabled', true)
            ->with('notificationSubscriptions')
            ->get();

        $this->info("[notify:midday] {$users->count()} users");

        $today = now(config('app.timezone'))->toDateString();

        foreach ($users as $user) {
            $consumed = MealLog::where('user_id', $user->id)
                ->whereDate('logged_at', $today)
                ->sum('calories');

            $remaining = max(0, ($user->calorie_goal ?? 2000) - $consumed);
            $body = $remaining > 0
                ? "Bạn còn thiếu {$remaining} kcal để đạt mục tiêu hôm nay. Hãy log bữa trưa!"
                : 'Bạn đã đạt mục tiêu calo hôm nay! 🎉 Tiếp tục duy trì nhé.';

            $tokens        = $user->notificationSubscriptions->pluck('fcm_token')->toArray();
            $invalidTokens = $fcm->sendMulticast(
                $tokens,
                'Nhắc nhở buổi trưa 🍱',
                $body,
                ['url' => '/scan', 'remaining_kcal' => (string) $remaining],
            );
            $this->removeInvalidTokens($invalidTokens);
        }
    }

    private function removeInvalidTokens(array $tokens): void
    {
        if (!empty($tokens)) {
            NotificationSubscription::whereIn('fcm_token', $tokens)->delete();
        }
    }
}
