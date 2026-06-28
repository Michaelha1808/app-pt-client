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

    public function __construct(public int $connectionId, public string $externalId) {}

    public function handle(HealthProviderFactory $providers, HealthActivityWriter $writer): void
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

        $writer->writeFromProvider($connection, $normalized);
        $connection->update(['last_synced_at' => now()]);
    }
}
