<?php

namespace App\Jobs;

use App\Models\HealthActivity;
use App\Models\HealthConnection;
use App\Models\NotificationLog;
use App\Models\NotificationSubscription;
use App\Models\User;
use App\Services\FcmService;
use App\Services\Health\HealthActivityWriter;
use App\Services\Health\HealthProviderFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fetch chi tiết 1 activity từ provider → upsert health_activities → cộng calo + streak.
 * Idempotent qua unique(provider, external_id) trong HealthActivityWriter.
 */
class SyncActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    /** Nhãn tiếng Việt cho từng loại bài tập (khớp config health.met). */
    private const TYPE_LABELS = [
        'walk'    => 'đi bộ',
        'run'     => 'chạy bộ',
        'ride'    => 'đạp xe',
        'swim'    => 'bơi',
        'workout' => 'tập gym',
        'yoga'    => 'yoga',
        'hike'    => 'leo núi',
        'other'   => 'tập luyện',
    ];

    public function __construct(public int $connectionId, public string $externalId) {}

    public function handle(HealthProviderFactory $providers, HealthActivityWriter $writer, FcmService $fcm): void
    {
        $connection = HealthConnection::with('user')->find($this->connectionId);
        if (!$connection || $connection->status !== 'active') {
            return;
        }

        $normalized = $providers->make($connection->provider)
            ->fetchActivity($connection, $this->externalId);

        if (!$normalized) {
            Log::info('SyncActivityJob: không lấy được activity', [
                'connection' => $this->connectionId, 'external' => $this->externalId,
            ]);
            return;
        }

        $activity = $writer->writeFromProvider($connection, $normalized);
        $connection->update(['last_synced_at' => now()]);

        // Chỉ chúc mừng khi đồng bộ buổi tập MỚI (tránh spam khi re-sync/backfill).
        // wasRecentlyCreated = false ở các lần retry nên không gửi trùng.
        if ($activity->wasRecentlyCreated && $activity->calories > 0) {
            $this->notifyActivitySynced($fcm, $connection->user, $activity);
        }
    }

    /**
     * Tạo log in-app + push chúc mừng kèm calo đốt được. Không để lỗi push làm job retry.
     */
    private function notifyActivitySynced(FcmService $fcm, User $user, HealthActivity $activity): void
    {
        try {
            $label = self::TYPE_LABELS[$activity->type] ?? self::TYPE_LABELS['other'];
            $what  = $activity->name ?: "buổi {$label}";

            $title = 'Đồng bộ buổi tập thành công 🎉';
            $body  = "Chúc mừng! {$what} vừa đốt {$activity->calories} kcal. Tiếp tục giữ phong độ nhé! 🔥";

            NotificationLog::create([
                'user_id' => $user->id,
                'type'    => 'activity_synced',
                'title'   => $title,
                'body'    => $body,
                'url'     => '/history',
            ]);

            $unread = NotificationLog::where('user_id', $user->id)->whereNull('read_at')->count();

            $tokens = $user->notificationSubscriptions->pluck('fcm_token')->toArray();
            $invalid = $fcm->sendMulticast($tokens, $title, $body, [
                'type'         => 'activity_synced',
                'url'          => '/history',
                'unread_count' => (string) $unread,
            ]);

            if (!empty($invalid)) {
                NotificationSubscription::whereIn('fcm_token', $invalid)->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('SyncActivityJob: gửi thông báo thất bại', [
                'user'  => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
