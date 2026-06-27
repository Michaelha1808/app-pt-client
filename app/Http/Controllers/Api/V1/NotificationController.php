<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Models\NotificationSubscription;
use App\Services\FcmService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function subscribe(Request $request)
    {
        $request->validate([
            'fcm_token'   => 'required|string|max:500',
            'device_type' => 'sometimes|in:ios,android,web',
        ]);

        NotificationSubscription::updateOrCreate(
            ['fcm_token' => $request->fcm_token],
            [
                'user_id'     => $request->user()->id,
                'device_type' => $request->device_type ?? 'web',
            ]
        );

        return response()->json(['message' => 'Đăng ký thành công']);
    }

    public function unsubscribe(Request $request)
    {
        $request->validate([
            'fcm_token' => 'required|string|max:500',
        ]);

        $request->user()
            ->notificationSubscriptions()
            ->where('fcm_token', $request->fcm_token)
            ->delete();

        return response()->noContent();
    }

    public function getSettings(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'morning' => [
                'enabled' => (bool) $user->morning_notify_enabled,
                'time'    => $user->morning_notify ? substr($user->morning_notify, 0, 5) : '07:00',
            ],
            'midday' => [
                'enabled' => (bool) $user->midday_notify_enabled,
            ],
            'evening' => [
                'enabled' => (bool) $user->evening_notify_enabled,
                'time'    => $user->evening_notify ? substr($user->evening_notify, 0, 5) : '20:00',
            ],
            'email_reengagement' => [
                'enabled' => (bool) $user->email_reengagement_enabled,
            ],
        ]);
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'morning.enabled'             => 'sometimes|boolean',
            'morning.time'                => 'sometimes|date_format:H:i',
            'midday.enabled'              => 'sometimes|boolean',
            'evening.enabled'             => 'sometimes|boolean',
            'evening.time'                => 'sometimes|date_format:H:i',
            'email_reengagement.enabled'  => 'sometimes|boolean',
        ]);

        $patch = [];

        if ($request->has('morning.enabled'))            $patch['morning_notify_enabled']     = $request->input('morning.enabled');
        if ($request->has('morning.time'))               $patch['morning_notify']              = $request->input('morning.time');
        if ($request->has('midday.enabled'))             $patch['midday_notify_enabled']       = $request->input('midday.enabled');
        if ($request->has('evening.enabled'))            $patch['evening_notify_enabled']      = $request->input('evening.enabled');
        if ($request->has('evening.time'))               $patch['evening_notify']              = $request->input('evening.time');
        if ($request->has('email_reengagement.enabled')) $patch['email_reengagement_enabled']  = $request->input('email_reengagement.enabled');

        if (!empty($patch)) {
            $request->user()->update($patch);
        }

        return response()->json(['message' => 'Cập nhật thành công']);
    }

    public function history(Request $request)
    {
        $logs = NotificationLog::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'type', 'title', 'body', 'url', 'read_at', 'created_at']);

        return response()->json($logs);
    }

    public function markAllRead(Request $request)
    {
        NotificationLog::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->noContent();
    }

    public function markRead(Request $request, NotificationLog $notificationLog)
    {
        abort_unless($notificationLog->user_id === $request->user()->id, 403);

        if (is_null($notificationLog->read_at)) {
            $notificationLog->update(['read_at' => now()]);
        }

        return response()->noContent();
    }

    // Chỉ dùng để test trong dev — gửi push thử đến thiết bị của chính user
    public function sendTest(Request $request, FcmService $fcm)
    {
        abort_unless(app()->isLocal() || app()->environment('staging'), 403, 'Only available in local/staging');

        $request->validate([
            'type' => 'required|in:morning,midday,evening',
        ]);

        $user   = $request->user();
        $tokens = $user->notificationSubscriptions()->pluck('fcm_token')->toArray();

        if (empty($tokens)) {
            return response()->json(['message' => 'Không có thiết bị đăng ký'], 404);
        }

        $messages = [
            'morning' => ['Chào buổi sáng! ☀️', 'Đừng quên log bữa sáng để theo dõi calo hôm nay nhé!'],
            'midday'  => ['Nhắc nhở buổi trưa 🍱', 'Bạn còn thiếu calo để đạt mục tiêu. Hãy log bữa trưa!'],
            'evening' => ['Tổng kết hôm nay 🌙', 'Hãy xem lại những gì bạn đã ăn hôm nay nhé!'],
        ];

        [$title, $body] = $messages[$request->type];

        $unread = NotificationLog::where('user_id', $user->id)->whereNull('read_at')->count();
        $invalidTokens = $fcm->sendMulticast($tokens, $title, $body, [
            'url'          => '/home',
            'unread_count' => (string) $unread,
        ]);

        if (!empty($invalidTokens)) {
            NotificationSubscription::whereIn('fcm_token', $invalidTokens)->delete();
        }

        $sent = count($tokens) - count($invalidTokens);
        return response()->json(['message' => "Đã gửi tới {$sent} thiết bị"]);
    }
}
