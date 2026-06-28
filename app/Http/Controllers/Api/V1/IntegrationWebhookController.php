<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SyncActivityJob;
use App\Models\HealthConnection;
use App\Services\Health\HealthProviderFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook provider — route PUBLIC (không Sanctum).
 * GET  = handshake xác minh subscription (echo challenge).
 * POST = event → CHỈ enqueue job rồi trả 200 ngay (Strava yêu cầu phản hồi ≤2s).
 *        Không tin payload (Strava không ký) → job luôn re-fetch từ API bằng token.
 */
class IntegrationWebhookController extends Controller
{
    public function __construct(private HealthProviderFactory $providers) {}

    public function verify(Request $request, string $provider): JsonResponse
    {
        abort_unless($this->providers->supports($provider), 404);

        $response = $this->providers->make($provider)->verifyWebhook($request);

        abort_if($response === null, 403, 'Xác minh webhook thất bại');

        return response()->json($response);
    }

    public function receive(Request $request, string $provider): JsonResponse
    {
        abort_unless($this->providers->supports($provider), 404);

        // Strava: { object_type, object_id, aspect_type, owner_id, ... }
        $objectType = $request->input('object_type');
        $aspectType = $request->input('aspect_type');
        $objectId   = $request->input('object_id');
        $ownerId    = $request->input('owner_id');

        if ($objectType === 'activity' && in_array($aspectType, ['create', 'update'], true)) {
            $connection = HealthConnection::where('provider', $provider)
                ->where('provider_user_id', (string) $ownerId)
                ->where('status', 'active')
                ->first();

            if ($connection) {
                SyncActivityJob::dispatch($connection->id, (string) $objectId);
            } else {
                Log::info('Webhook event cho owner chưa có connection active', [
                    'provider' => $provider, 'owner_id' => $ownerId,
                ]);
            }
        }

        // Luôn 200 nhanh để provider không retry.
        return response()->json(['received' => true]);
    }
}
