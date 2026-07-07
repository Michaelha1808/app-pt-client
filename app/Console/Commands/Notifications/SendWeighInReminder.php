<?php

namespace App\Console\Commands\Notifications;

use App\Console\Commands\Notifications\Concerns\DispatchesUserPush;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Console\Command;

class SendWeighInReminder extends Command
{
    use DispatchesUserPush;

    protected $signature   = 'notify:weigh-in';
    protected $description = 'Nhắc user cân nặng nếu chưa ghi trong 7 ngày qua (chạy hàng tuần)';

    public function handle(FcmService $fcm): void
    {
        $weekAgo = now(config('app.timezone'))->subDays(7)->toDateString();

        $users = User::where('status', 'active')
            ->where('weigh_in_reminder_enabled', true)
            ->whereHas('notificationSubscriptions')
            ->whereDoesntHave('weightLogs', fn ($q) => $q->where('logged_date', '>=', $weekAgo))
            ->with('notificationSubscriptions')
            ->get();

        foreach ($users as $user) {
            $this->dispatchPush($fcm, $user, [
                'type'  => 'weigh_in_reminder',
                'title' => '⚖️ Cập nhật cân nặng tuần này',
                'body'  => 'Ghi lại cân nặng để theo dõi tiến độ nhé!',
                'url'   => '/weight',
            ]);
        }

        $this->info("[notify:weigh-in] nhắc {$users->count()} user chưa cân trong 7 ngày");
    }
}
