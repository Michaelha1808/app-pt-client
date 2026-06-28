<?php

namespace App\Jobs;

use App\Models\HealthConnection;
use App\Services\Health\HealthProviderFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Cron: refresh token sắp hết hạn (Strava ~6h/lần) để webhook/sync không gãy
 * khi user không vào app. Quét connection active có token_expires_at < +30 phút.
 */
class RefreshExpiringTokensJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function handle(HealthProviderFactory $providers): void
    {
        HealthConnection::query()
            ->where('status', 'active')
            ->whereNotNull('refresh_token')
            ->where(fn ($q) => $q
                ->whereNull('token_expires_at')
                ->orWhere('token_expires_at', '<', now()->addMinutes(30)))
            ->chunkById(100, function ($connections) use ($providers) {
                foreach ($connections as $connection) {
                    try {
                        $tokens = $providers->make($connection->provider)
                            ->refresh($connection->refresh_token);

                        $connection->update([
                            'access_token'     => $tokens->accessToken,
                            'refresh_token'    => $tokens->refreshToken ?? $connection->refresh_token,
                            'token_expires_at' => $tokens->expiresAt,
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('RefreshExpiringTokens thất bại', [
                            'connection' => $connection->id, 'msg' => $e->getMessage(),
                        ]);
                        $connection->update(['status' => 'error']);
                    }
                }
            });
    }
}
