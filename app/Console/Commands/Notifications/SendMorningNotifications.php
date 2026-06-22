<?php

namespace App\Console\Commands\Notifications;

use App\Models\NotificationSubscription;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Console\Command;

class SendMorningNotifications extends Command
{
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

        foreach ($users as $user) {
            $tokens       = $user->notificationSubscriptions->pluck('fcm_token')->toArray();
            $invalidTokens = $fcm->sendMulticast(
                $tokens,
                'Chào buổi sáng! ☀️',
                'Đừng quên log bữa sáng để theo dõi calo hôm nay nhé!',
                ['url' => '/scan'],
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
