<?php

namespace App\Console\Commands\Notifications;

use App\Console\Commands\Notifications\Concerns\DispatchesUserPush;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Console\Command;

class SendMorningNotifications extends Command
{
    use DispatchesUserPush;

    protected $signature   = 'notify:morning';
    protected $description = 'Gửi thông báo đầu ngày cho users theo giờ đã cài';

    public function handle(FcmService $fcm): void
    {
        // Cửa sổ vài phút gần nhất (catch-up nếu scheduler lỡ đúng phút cài).
        $window = $this->recentMinutes();

        // Tìm users bật thông báo đầu ngày và giờ rơi vào cửa sổ.
        $users = User::where('morning_notify_enabled', true)
            ->whereRaw("TO_CHAR(morning_notify, 'HH24:MI') IN (" . implode(',', array_fill(0, count($window), '?')) . ")", $window)
            ->with('notificationSubscriptions')
            ->get();

        $this->info('[notify:morning] ' . implode('/', $window) . " — {$users->count()} users");

        $title = 'Chào buổi sáng! ☀️';
        $body  = 'Đừng quên log bữa sáng để theo dõi calo hôm nay nhé!';

        foreach ($users as $user) {
            // Chống gửi trùng: mỗi user chỉ nhận 1 lần/ngày dù cửa sổ rộng nhiều phút.
            if ($this->alreadySentToday($user, 'morning')) continue;

            $this->dispatchPush($fcm, $user, [
                'type'  => 'morning',
                'title' => $title,
                'body'  => $body,
                'url'   => '/scan',
            ]);
        }
    }
}
