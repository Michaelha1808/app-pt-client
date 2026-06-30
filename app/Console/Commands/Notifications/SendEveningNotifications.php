<?php

namespace App\Console\Commands\Notifications;

use App\Console\Commands\Notifications\Concerns\DispatchesUserPush;
use App\Models\MealLog;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Console\Command;

class SendEveningNotifications extends Command
{
    use DispatchesUserPush;

    protected $signature   = 'notify:evening';
    protected $description = 'Gửi thông báo cuối ngày cho users theo giờ đã cài';

    public function handle(FcmService $fcm): void
    {
        // Cửa sổ vài phút gần nhất (catch-up nếu scheduler lỡ đúng phút cài).
        $window = $this->recentMinutes();

        $users = User::where('evening_notify_enabled', true)
            ->whereRaw("TO_CHAR(evening_notify, 'HH24:MI') IN (" . implode(',', array_fill(0, count($window), '?')) . ")", $window)
            ->with('notificationSubscriptions')
            ->get();

        $this->info('[notify:evening] ' . implode('/', $window) . " — {$users->count()} users");

        $today = now(config('app.timezone'))->toDateString();

        foreach ($users as $user) {
            // Chống gửi trùng: mỗi user chỉ nhận 1 lần/ngày dù cửa sổ rộng nhiều phút.
            if ($this->alreadySentToday($user, 'evening')) continue;

            $consumed = MealLog::where('user_id', $user->id)
                ->whereDate('logged_at', $today)
                ->sum('calories');

            $goal = $user->calorie_goal ?? 2000;
            $body = $consumed >= $goal
                ? "Bạn đã nạp {$consumed}/{$goal} kcal. Đã đạt mục tiêu hôm nay! 🎉"
                : "Bạn đã nạp {$consumed}/{$goal} kcal. Còn thiếu " . ($goal - $consumed) . " kcal.";

            $title = 'Tổng kết hôm nay 🌙';
            $this->dispatchPush($fcm, $user, [
                'type'  => 'evening',
                'title' => $title,
                'body'  => $body,
                'url'   => '/history',
            ]);
        }
    }
}
