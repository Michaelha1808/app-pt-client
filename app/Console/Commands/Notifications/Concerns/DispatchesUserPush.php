<?php

namespace App\Console\Commands\Notifications\Concerns;

use App\Models\NotificationLog;
use App\Models\NotificationSubscription;
use App\Models\User;
use App\Services\FcmService;

trait DispatchesUserPush
{
    /**
     * Lưu NotificationLog, tính số thông báo chưa đọc, gửi push (kèm unread_count
     * để app hiển thị badge trên icon) rồi dọn các token đã hỏng.
     *
     * @param array{type:string,title:string,body:string,url:string} $log
     * @param array<string,string> $extraData Dữ liệu push bổ sung (vd remaining_kcal, action)
     */
    protected function dispatchPush(FcmService $fcm, User $user, array $log, array $extraData = []): void
    {
        // Tạo log TRƯỚC để số chưa đọc tính được tính cả thông báo vừa gửi.
        NotificationLog::create([
            'user_id' => $user->id,
            'type'    => $log['type'],
            'title'   => $log['title'],
            'body'    => $log['body'],
            'url'     => $log['url'],
        ]);

        $unread = NotificationLog::where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        $tokens  = $user->notificationSubscriptions->pluck('fcm_token')->toArray();
        $invalid = $fcm->sendMulticast($tokens, $log['title'], $log['body'], array_merge($extraData, [
            'url'          => $log['url'],
            'unread_count' => (string) $unread,
        ]));

        if (!empty($invalid)) {
            NotificationSubscription::whereIn('fcm_token', $invalid)->delete();
        }
    }
}
