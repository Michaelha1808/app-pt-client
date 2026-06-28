<?php

namespace App\Jobs;

use App\Models\HealthConnection;
use App\Services\Health\HealthActivityWriter;
use App\Services\Health\HealthProviderFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sau khi kết nối lần đầu: kéo N activity gần nhất (config health.backfill_days)
 * để có sẵn lịch sử, không phụ thuộc webhook. Idempotent → chạy lại an toàn.
 */
class BackfillActivitiesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public int $connectionId) {}

    public function handle(HealthProviderFactory $providers, HealthActivityWriter $writer): void
    {
        $connection = HealthConnection::with('user')->find($this->connectionId);
        if (!$connection || $connection->status !== 'active') {
            return;
        }

        $activities = $providers->make($connection->provider)
            ->fetchRecentActivities($connection, 30);

        foreach ($activities as $normalized) {
            $writer->writeFromProvider($connection, $normalized);
        }

        $connection->update(['last_synced_at' => now()]);
    }
}
