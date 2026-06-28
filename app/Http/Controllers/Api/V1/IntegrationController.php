<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\BackfillActivitiesJob;
use App\Models\HealthActivity;
use App\Models\HealthConnection;
use App\Services\Health\HealthActivityWriter;
use App\Services\Health\HealthProviderFactory;
use App\Services\StreakService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class IntegrationController extends Controller
{
    public function __construct(private HealthProviderFactory $providers) {}

    /**
     * Bắt đầu OAuth: trả authorize URL để FE redirect cả tab.
     * State = payload mã hoá (user_id + ts) → callback (route public) nhận lại đúng user, chống CSRF.
     */
    public function connect(Request $request, string $provider): JsonResponse
    {
        abort_unless($this->providers->supports($provider), 404, 'Provider không hỗ trợ');

        $state = Crypt::encryptString(json_encode([
            'user_id'  => $request->user()->id,
            'provider' => $provider,
            'ts'       => now()->timestamp,
        ]));

        $url = $this->providers->make($provider)->authorizeUrl($state);

        return response()->json(['url' => $url]);
    }

    /**
     * Callback OAuth (route PUBLIC, không Sanctum) — đổi code → token, lưu connection,
     * đăng ký webhook + backfill, rồi redirect về FE.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $frontend = rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/');
        $redirect = fn (string $status) => redirect("{$frontend}/integrations/callback?provider={$provider}&status={$status}");

        if ($request->query('error') || !$request->filled('code') || !$request->filled('state')) {
            return $redirect('denied');
        }

        // Giải mã state → user_id (chống CSRF / nhận đúng user).
        try {
            $payload = json_decode(Crypt::decryptString($request->query('state')), true);
            $userId  = $payload['user_id'] ?? null;
            abort_unless($userId && ($payload['provider'] ?? null) === $provider, 400);
        } catch (\Throwable) {
            return $redirect('invalid_state');
        }

        try {
            $client = $this->providers->make($provider);
            $tokens = $client->exchangeCode($request->query('code'));

            $connection = HealthConnection::updateOrCreate(
                ['user_id' => $userId, 'provider' => $provider],
                [
                    'provider_user_id' => $tokens->providerUserId,
                    'access_token'     => $tokens->accessToken,
                    'refresh_token'    => $tokens->refreshToken,
                    'token_expires_at' => $tokens->expiresAt,
                    'scopes'           => $tokens->scopes,
                    'status'           => 'active',
                ],
            );

            // Webhook subscription dùng chung cho cả app (đăng ký 1 lần) — best-effort.
            if (!$connection->webhook_id) {
                $connection->update(['webhook_id' => $client->registerWebhook()]);
            }

            BackfillActivitiesJob::dispatch($connection->id);
        } catch (\Throwable $e) {
            Log::error('Health connect callback failed', ['provider' => $provider, 'msg' => $e->getMessage()]);
            return $redirect('error');
        }

        return $redirect('success');
    }

    /**
     * Ngắt kết nối: revoke phía provider + xoá connection (activity giữ lại làm lịch sử).
     */
    public function disconnect(Request $request, string $provider): JsonResponse
    {
        $connection = $request->user()->healthConnections()
            ->where('provider', $provider)->first();

        abort_unless($connection, 404, 'Chưa kết nối provider này');

        try {
            $this->providers->make($provider)->revoke($connection);
        } catch (\Throwable $e) {
            Log::info('Disconnect revoke best-effort failed', ['msg' => $e->getMessage()]);
        }

        $connection->delete();

        return response()->json(['message' => 'Đã ngắt kết nối']);
    }

    /**
     * Danh sách provider đã kết nối + trạng thái. KHÔNG trả token.
     * (Phase A chưa có provider thật → thường rỗng; FE vẫn render được nút kết nối.)
     */
    public function index(Request $request): JsonResponse
    {
        $connections = $request->user()->healthConnections()
            ->get(['id', 'provider', 'status', 'scopes', 'last_synced_at', 'created_at'])
            ->map(fn ($c) => [
                'id'             => $c->id,
                'provider'       => $c->provider,
                'status'         => $c->status,
                'scopes'         => $c->scopes,
                'last_synced_at' => $c->last_synced_at?->toIso8601String(),
                'connected_at'   => $c->created_at->toIso8601String(),
            ]);

        // Provider đã cấu hình credential (chưa set key → FE hiện "Sắp ra mắt", không cho bấm kết nối).
        $available = collect(['strava'])
            ->filter(fn ($p) => (bool) config("services.$p.client_id"))
            ->values();

        return response()->json([
            'connections'         => $connections,
            'available_providers' => $available,
        ]);
    }

    /**
     * Feed buổi tập (gồm cả manual lẫn provider), phân trang.
     */
    public function activities(Request $request): JsonResponse
    {
        $activities = $request->user()->healthActivities()
            ->orderByDesc('started_at')
            ->paginate(20, [
                'id', 'provider', 'source', 'type', 'name',
                'started_at', 'duration_seconds', 'distance_meters', 'calories',
            ]);

        return response()->json([
            'data' => collect($activities->items())->map(fn ($a) => $this->present($a)),
            'meta' => [
                'current_page' => $activities->currentPage(),
                'last_page'    => $activities->lastPage(),
                'total'        => $activities->total(),
            ],
        ]);
    }

    /**
     * Log buổi tập THỦ CÔNG (fallback cho user không có Strava).
     * Thiếu `calories` → ước lượng bằng MET (config/health.php) theo cân nặng profile.
     */
    public function storeManual(Request $request, StreakService $streakService): JsonResponse
    {
        $metKeys = array_keys(config('health.met'));

        $data = $request->validate([
            'type'             => ['required', 'string', Rule::in($metKeys)],
            'started_at'       => ['nullable', 'date'],
            'duration_seconds' => ['required', 'integer', 'min:1', 'max:86400'],
            'distance_meters'  => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'calories'         => ['nullable', 'integer', 'min:0', 'max:30000'],
            'name'             => ['nullable', 'string', 'max:120'],
        ]);

        $user      = $request->user();
        $startedAt = isset($data['started_at']) ? Carbon::parse($data['started_at']) : now();
        $calories  = $data['calories'] ?? HealthActivityWriter::estimateCalories($data['type'], $data['duration_seconds'], $user->weight_kg);

        $activity = $user->healthActivities()->create([
            'provider'         => 'manual',
            'source'           => 'manual',
            'type'             => $data['type'],
            'name'             => $data['name'] ?? null,
            'started_at'       => $startedAt,
            'duration_seconds' => $data['duration_seconds'],
            'distance_meters'  => $data['distance_meters'] ?? null,
            'calories'         => $calories,
        ]);

        $streak = $streakService->recordActivity(
            $user->load('streakMilestones', 'notificationSubscriptions')
        );

        return response()->json([
            'message'  => 'Đã lưu buổi tập',
            'activity' => $this->present($activity),
            'streak'   => $streak,
        ], 201);
    }

    /**
     * Xoá buổi tập thủ công. KHÔNG cho xoá row provider (tránh lệch với nguồn ngoài).
     */
    public function destroyManual(Request $request, HealthActivity $activity): JsonResponse
    {
        abort_if($activity->user_id !== $request->user()->id, 403);
        abort_if($activity->source !== 'manual', 422, 'Chỉ xoá được buổi tập tự thêm');

        $activity->delete();

        return response()->json(['message' => 'Đã xoá buổi tập']);
    }

    // -----------------------------------------------------------------

    private function present(HealthActivity $a): array
    {
        return [
            'id'               => $a->id,
            'provider'         => $a->provider,
            'source'           => $a->source,
            'type'             => $a->type,
            'name'             => $a->name,
            'started_at'       => $a->started_at->toIso8601String(),
            'duration_seconds' => $a->duration_seconds,
            'distance_meters'  => $a->distance_meters,
            'calories'         => $a->calories,
        ];
    }
}
