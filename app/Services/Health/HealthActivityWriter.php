<?php

namespace App\Services\Health;

use App\Models\HealthActivity;
use App\Models\HealthConnection;
use App\Models\User;
use App\Services\StreakService;

/**
 * Ghi 1 buổi tập (từ provider hoặc manual) vào health_activities — idempotent —
 * ước lượng calo nếu thiếu, rồi đẩy streak. Dùng chung cho job sync/backfill và manual log.
 */
class HealthActivityWriter
{
    public function __construct(private StreakService $streakService) {}

    /**
     * Ước lượng calo bằng MET: calories ≈ MET × kg × giờ.
     */
    public static function estimateCalories(string $type, int $durationSeconds, ?float $weightKg): int
    {
        $met    = config("health.met.$type", config('health.met.other'));
        $weight = $weightKg ?: config('health.default_weight_kg');

        return (int) round($met * $weight * ($durationSeconds / 3600));
    }

    /**
     * Upsert 1 activity từ provider. $normalized theo cột health_activities (StravaProvider::normalize).
     * Idempotent qua unique(provider, external_id). Trả về activity (đã tạo/cập nhật).
     */
    public function writeFromProvider(HealthConnection $connection, array $normalized): HealthActivity
    {
        $user = $connection->user;

        $calories = $normalized['calories']
            ?? self::estimateCalories($normalized['type'], $normalized['duration_seconds'], $user->weight_kg);

        $activity = HealthActivity::updateOrCreate(
            ['provider' => $connection->provider, 'external_id' => $normalized['external_id']],
            [
                'user_id'              => $user->id,
                'health_connection_id' => $connection->id,
                'source'               => 'provider',
                'type'                 => $normalized['type'],
                'name'                 => $normalized['name'] ?? null,
                'started_at'           => $normalized['started_at'],
                'duration_seconds'     => $normalized['duration_seconds'],
                'distance_meters'      => $normalized['distance_meters'] ?? null,
                'calories'             => $calories,
                'raw'                  => $normalized['raw'] ?? null,
            ],
        );

        // Buổi tập giữ streak như bữa ăn (idempotent theo ngày).
        $this->streakService->recordActivity(
            $user->load('streakMilestones', 'notificationSubscriptions')
        );

        return $activity;
    }
}
