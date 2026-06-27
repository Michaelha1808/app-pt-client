<?php

namespace App\Jobs;

use App\Models\NotificationCampaign;
use App\Models\NotificationLog;
use App\Models\NotificationSubscription;
use App\Models\User;
use App\Services\FcmService;
use App\Support\NotificationAudience;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendBroadcastNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Không retry để tránh gửi trùng. */
    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(public int $campaignId) {}

    public function handle(FcmService $fcm): void
    {
        $campaign = NotificationCampaign::find($this->campaignId);
        if (! $campaign || $campaign->status === 'done') {
            return;
        }

        $campaign->update(['status' => 'sending']);

        $sent = 0;
        $push = 0;

        try {
            NotificationAudience::query($campaign->segment ?? [])
                ->with('notificationSubscriptions')
                ->chunkById(200, function ($users) use ($fcm, $campaign, &$sent, &$push) {
                    foreach ($users as $user) {
                        $push += $this->deliver($fcm, $user, $campaign);
                        $sent++;
                    }
                    $campaign->update(['sent_count' => $sent, 'push_count' => $push]);
                });

            $campaign->update(['status' => 'done', 'sent_count' => $sent, 'push_count' => $push]);
        } catch (\Throwable $e) {
            $campaign->update(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 1000)]);
            throw $e;
        }
    }

    /**
     * Tạo log in-app + gửi push tới mọi thiết bị của user.
     * @return int số thiết bị đã gửi push thành công
     */
    private function deliver(FcmService $fcm, User $user, NotificationCampaign $campaign): int
    {
        NotificationLog::create([
            'user_id' => $user->id,
            'type'    => 'broadcast',
            'title'   => $campaign->title,
            'body'    => $campaign->body,
            'url'     => $campaign->url ?: '/home',
        ]);

        $tokens = $user->notificationSubscriptions->pluck('fcm_token')->toArray();
        if (empty($tokens)) {
            return 0;
        }

        $unread = NotificationLog::where('user_id', $user->id)->whereNull('read_at')->count();

        $invalid = $fcm->sendMulticast($tokens, $campaign->title, $campaign->body, [
            'url'          => $campaign->url ?: '/home',
            'unread_count' => (string) $unread,
        ]);

        if (! empty($invalid)) {
            NotificationSubscription::whereIn('fcm_token', $invalid)->delete();
        }

        return count($tokens) - count($invalid);
    }

    public function failed(\Throwable $e): void
    {
        NotificationCampaign::where('id', $this->campaignId)
            ->update(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 1000)]);
    }
}
