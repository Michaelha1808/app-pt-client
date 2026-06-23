<?php

namespace App\Console\Commands\Notifications;

use App\Models\MealLog;
use App\Models\NotificationLog;
use App\Models\NotificationSubscription;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Console\Command;

class SendEveningNotifications extends Command
{
    protected $signature   = 'notify:evening';
    protected $description = 'Gửi thông báo cuối ngày cho users theo giờ đã cài';

    public function handle(FcmService $fcm): void
    {
        $now = now(config('app.timezone'))->format('H:i');

        $users = User::where('evening_notify_enabled', true)
            ->whereRaw("TO_CHAR(evening_notify, 'HH24:MI') = ?", [$now])
            ->with('notificationSubscriptions')
            ->get();

        $this->info("[notify:evening] {$now} — {$users->count()} users");

        $today = now(config('app.timezone'))->toDateString();

        foreach ($users as $user) {
            $consumed = MealLog::where('user_id', $user->id)
                ->whereDate('logged_at', $today)
                ->sum('calories');

            $goal = $user->calorie_goal ?? 2000;
            $body = $consumed >= $goal
                ? "Bạn đã nạp {$consumed}/{$goal} kcal. Đã đạt mục tiêu hôm nay! 🎉"
                : "Bạn đã nạp {$consumed}/{$goal} kcal. Còn thiếu " . ($goal - $consumed) . " kcal.";

            $title         = 'Tổng kết hôm nay 🌙';
            $tokens        = $user->notificationSubscriptions->pluck('fcm_token')->toArray();
            $invalidTokens = $fcm->sendMulticast(
                $tokens,
                $title,
                $body,
                ['url' => '/history'],
            );
            $this->removeInvalidTokens($invalidTokens);

            NotificationLog::create([
                'user_id' => $user->id,
                'type'    => 'evening',
                'title'   => $title,
                'body'    => $body,
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
