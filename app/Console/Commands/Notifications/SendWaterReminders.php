<?php

namespace App\Console\Commands\Notifications;

use App\Console\Commands\Notifications\Concerns\DispatchesUserPush;
use App\Models\User;
use App\Models\WaterLog;
use App\Services\FcmService;
use Illuminate\Console\Command;

class SendWaterReminders extends Command
{
    use DispatchesUserPush;

    protected $signature   = 'notify:water';
    protected $description = 'Nhắc uống nước nếu chưa đủ 2 lít (chạy 2 mốc/ngày)';

    /** Mục tiêu nước cố định (ml) — khớp WATER_GOAL_ML phía client. */
    private const GOAL_ML = 2000;

    public function handle(FcmService $fcm): void
    {
        $today = now(config('app.timezone'))->toDateString();

        // Chỉ gửi cho user đang hoạt động và có thiết bị đăng ký push.
        $users = User::where('status', 'active')
            ->whereHas('notificationSubscriptions')
            ->with('notificationSubscriptions')
            ->get();

        $sent = 0;
        foreach ($users as $user) {
            $total = (int) WaterLog::where('user_id', $user->id)
                ->whereDate('logged_at', $today)
                ->sum('amount_ml');

            if ($total >= self::GOAL_ML) {
                continue;
            }

            $remaining = self::GOAL_ML - $total;
            $this->dispatchPush($fcm, $user, [
                'type'  => 'water',
                'title' => 'Nhắc uống nước 💧',
                'body'  => "Bạn mới uống {$total}/2000ml hôm nay. Uống thêm {$remaining}ml cho đủ nhé!",
                'url'   => '/home',
            ]);
            $sent++;
        }

        $this->info("[notify:water] nhắc {$sent}/{$users->count()} user chưa đủ 2L");
    }
}
