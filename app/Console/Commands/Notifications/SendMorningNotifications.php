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
        // Lấy giờ hiện tại theo timezone app (HH:MM)
        $now = now(config('app.timezone'))->format('H:i');

        // Tìm users bật thông báo đầu ngày và giờ khớp với bây giờ
        $users = User::where('morning_notify_enabled', true)
            ->whereRaw("TO_CHAR(morning_notify, 'HH24:MI') = ?", [$now])
            ->with('notificationSubscriptions')
            ->get();

        $this->info("[notify:morning] {$now} — {$users->count()} users");

        $title = 'Chào buổi sáng! ☀️';
        $body  = 'Đừng quên log bữa sáng để theo dõi calo hôm nay nhé!';

        foreach ($users as $user) {
            $this->dispatchPush($fcm, $user, [
                'type'  => 'morning',
                'title' => $title,
                'body'  => $body,
                'url'   => '/scan',
            ]);
        }
    }
}
