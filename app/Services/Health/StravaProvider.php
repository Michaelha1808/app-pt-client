<?php

namespace App\Services\Health;

use App\Models\HealthConnection;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StravaProvider implements HealthProvider
{
    private const AUTH_URL  = 'https://www.strava.com/oauth/authorize';
    private const TOKEN_URL = 'https://www.strava.com/oauth/token';
    private const API_BASE  = 'https://www.strava.com/api/v3';
    private const SCOPE     = 'activity:read';

    /** Map sport_type của Strava → ActivityType nội bộ (để chọn icon/label). */
    private const TYPE_MAP = [
        'run' => 'run', 'trailrun' => 'run', 'virtualrun' => 'run',
        'ride' => 'ride', 'virtualride' => 'ride', 'ebikeride' => 'ride', 'mountainbikeride' => 'ride',
        'swim' => 'swim',
        'walk' => 'walk',
        'hike' => 'hike',
        'weighttraining' => 'workout', 'workout' => 'workout', 'crossfit' => 'workout', 'hiit' => 'workout',
        'yoga' => 'yoga',
    ];

    public function key(): string
    {
        return 'strava';
    }

    public function authorizeUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'       => config('services.strava.client_id'),
            'redirect_uri'    => config('services.strava.redirect'),
            'response_type'   => 'code',
            'approval_prompt' => 'auto',
            'scope'           => self::SCOPE,
            'state'           => $state,
        ]);
    }

    public function exchangeCode(string $code): TokenSet
    {
        $res = Http::asForm()->post(self::TOKEN_URL, [
            'client_id'     => config('services.strava.client_id'),
            'client_secret' => config('services.strava.client_secret'),
            'code'          => $code,
            'grant_type'    => 'authorization_code',
        ])->throw()->json();

        return $this->toTokenSet($res);
    }

    public function refresh(string $refreshToken): TokenSet
    {
        $res = Http::asForm()->post(self::TOKEN_URL, [
            'client_id'     => config('services.strava.client_id'),
            'client_secret' => config('services.strava.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ])->throw()->json();

        return $this->toTokenSet($res);
    }

    public function fetchActivity(HealthConnection $connection, string $externalId): ?array
    {
        $res = Http::withToken($this->accessToken($connection))
            ->get(self::API_BASE . "/activities/{$externalId}");

        if (!$res->successful()) {
            Log::warning('Strava fetchActivity failed', ['id' => $externalId, 'status' => $res->status()]);
            return null;
        }

        return $this->normalize($res->json());
    }

    public function fetchRecentActivities(HealthConnection $connection, int $limit): array
    {
        $res = Http::withToken($this->accessToken($connection))
            ->get(self::API_BASE . '/athlete/activities', [
                'per_page' => $limit,
                'after'    => now()->subDays((int) config('health.backfill_days', 7))->timestamp,
            ]);

        if (!$res->successful()) {
            Log::warning('Strava fetchRecentActivities failed', ['status' => $res->status()]);
            return [];
        }

        return array_map(fn ($a) => $this->normalize($a), $res->json());
    }

    public function registerWebhook(): ?string
    {
        $callback = config('services.strava.webhook_url');
        if (!$callback) {
            return null;
        }

        $res = Http::asForm()->post(self::API_BASE . '/push_subscriptions', [
            'client_id'     => config('services.strava.client_id'),
            'client_secret' => config('services.strava.client_secret'),
            'callback_url'  => $callback,
            'verify_token'  => config('services.strava.verify_token'),
        ]);

        if (!$res->successful()) {
            Log::warning('Strava registerWebhook failed', ['body' => $res->body()]);
            return null;
        }

        return (string) ($res->json('id') ?? '');
    }

    public function verifyWebhook(Request $request): ?array
    {
        if ($request->query('hub_mode') !== 'subscribe' && $request->query('hub.mode') !== 'subscribe') {
            return null;
        }

        $verifyToken = $request->query('hub.verify_token', $request->query('hub_verify_token'));
        if ($verifyToken !== config('services.strava.verify_token')) {
            return null;
        }

        $challenge = $request->query('hub.challenge', $request->query('hub_challenge'));

        return ['hub.challenge' => $challenge];
    }

    public function revoke(HealthConnection $connection): void
    {
        try {
            Http::asForm()->post('https://www.strava.com/oauth/deauthorize', [
                'access_token' => $this->accessToken($connection),
            ]);
        } catch (\Throwable $e) {
            Log::info('Strava revoke best-effort failed', ['msg' => $e->getMessage()]);
        }
    }

    // -----------------------------------------------------------------

    private function toTokenSet(array $res): TokenSet
    {
        return new TokenSet(
            accessToken:    $res['access_token'],
            refreshToken:   $res['refresh_token'] ?? null,
            expiresAt:      isset($res['expires_at']) ? Carbon::createFromTimestamp($res['expires_at']) : null,
            providerUserId: isset($res['athlete']['id']) ? (string) $res['athlete']['id'] : null,
            scopes:         self::SCOPE,
        );
    }

    /**
     * Access token còn hạn — tự refresh nếu sắp/đã hết hạn rồi lưu lại.
     */
    private function accessToken(HealthConnection $connection): string
    {
        $expiresAt = $connection->token_expires_at;
        if ($expiresAt && $expiresAt->isAfter(now()->addMinutes(5))) {
            return $connection->access_token;
        }

        if (!$connection->refresh_token) {
            return $connection->access_token;
        }

        $tokens = $this->refresh($connection->refresh_token);
        $connection->update([
            'access_token'     => $tokens->accessToken,
            'refresh_token'    => $tokens->refreshToken ?? $connection->refresh_token,
            'token_expires_at' => $tokens->expiresAt,
        ]);

        return $tokens->accessToken;
    }

    /**
     * Chuẩn hoá payload Strava → mảng theo cột health_activities (chưa gồm calo ước lượng).
     */
    private function normalize(array $a): array
    {
        $sport = strtolower((string) ($a['sport_type'] ?? $a['type'] ?? 'other'));

        return [
            'external_id'      => (string) $a['id'],
            'type'             => self::TYPE_MAP[$sport] ?? 'other',
            'name'             => $a['name'] ?? null,
            'started_at'       => isset($a['start_date']) ? Carbon::parse($a['start_date']) : now(),
            'duration_seconds' => (int) ($a['moving_time'] ?? $a['elapsed_time'] ?? 0),
            'distance_meters'  => isset($a['distance']) ? (int) round($a['distance']) : null,
            'calories'         => isset($a['calories']) ? (int) round($a['calories']) : null,
            'raw'              => $a,
        ];
    }
}
