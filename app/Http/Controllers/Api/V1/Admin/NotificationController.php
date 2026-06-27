<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendBroadcastNotification;
use App\Models\NotificationCampaign;
use App\Support\AuditLogger;
use App\Support\NotificationAudience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    private function segmentRules(): array
    {
        return [
            'segment'                 => 'sometimes|array',
            'segment.audience'        => 'sometimes|in:all,segment',
            'segment.role'            => 'nullable|in:user,admin',
            'segment.provider'        => 'nullable|in:email,google,facebook',
            'segment.gender'          => 'nullable|in:male,female,other',
            'segment.activity'        => 'nullable|in:active_7d,inactive_7d,inactive_30d',
            'segment.has_streak'      => 'sometimes|boolean',
            'segment.only_subscribed' => 'sometimes|boolean',
        ];
    }

    /** Ước lượng số người nhận trước khi gửi. */
    public function preview(Request $request): JsonResponse
    {
        $request->validate($this->segmentRules());
        $segment = $request->input('segment', ['audience' => 'all']);

        $base = NotificationAudience::query($segment);

        return response()->json([
            'audience_count'   => (clone $base)->count(),
            'subscribed_count' => (clone $base)->whereHas('notificationSubscriptions')->count(),
        ]);
    }

    /** Tạo chiến dịch + đẩy job gửi nền. */
    public function send(Request $request): JsonResponse
    {
        $request->validate(array_merge([
            'title' => 'required|string|max:120',
            'body'  => 'required|string|max:500',
            'url'   => 'nullable|string|max:255',
        ], $this->segmentRules()));

        $segment  = $request->input('segment', ['audience' => 'all']);
        $audience = NotificationAudience::query($segment)->count();

        if ($audience === 0) {
            return response()->json(['detail' => 'Không có người dùng nào khớp phân khúc đã chọn'], 422);
        }

        $campaign = NotificationCampaign::create([
            'admin_id'       => $request->user()->id,
            'title'          => $request->input('title'),
            'body'           => $request->input('body'),
            'url'            => $request->input('url'),
            'segment'        => $segment,
            'audience_count' => $audience,
            'status'         => 'queued',
        ]);

        SendBroadcastNotification::dispatch($campaign->id);

        AuditLogger::log('notification.broadcast', 'campaign', (string) $campaign->id, [
            'title'    => $campaign->title,
            'audience' => $audience,
            'segment'  => $segment,
        ]);

        return response()->json($this->format($campaign), 201);
    }

    /** Lịch sử chiến dịch. */
    public function index(Request $request): JsonResponse
    {
        $paginator = NotificationCampaign::query()
            ->with('admin:id,name,email')
            ->latest()
            ->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($c) => $this->format($c)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    private function format(NotificationCampaign $c): array
    {
        return [
            'id'             => $c->id,
            'title'          => $c->title,
            'body'           => $c->body,
            'url'            => $c->url,
            'segment'        => $c->segment,
            'audience_count' => $c->audience_count,
            'sent_count'     => $c->sent_count,
            'push_count'     => $c->push_count,
            'status'         => $c->status,
            'admin'          => $c->admin ? ['id' => $c->admin->id, 'name' => $c->admin->name] : null,
            'created_at'     => optional($c->created_at)->toIso8601String(),
        ];
    }
}
