<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FcmService
{
    public function __construct(private Messaging $messaging) {}

    /**
     * Gửi push notification tới một token.
     * Trả về true nếu thành công, false nếu token không hợp lệ (nên xoá).
     */
    public function send(string $token, string $title, string $body, array $data = []): bool
    {
        try {
            $message = CloudMessage::withTarget('token', $token)
                ->withNotification(Notification::create($title, $body))
                ->withData($data);

            $this->messaging->send($message);
            return true;
        } catch (\Kreait\Firebase\Exception\Messaging\NotFound $e) {
            // Token đã bị huỷ (user gỡ app / revoke permission)
            return false;
        } catch (\Throwable $e) {
            Log::warning('[FCM] Send failed', ['token' => substr($token, 0, 20), 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Gửi tới nhiều tokens, tự xoá token không hợp lệ.
     * Trả về danh sách token cần xoá.
     */
    public function sendMulticast(array $tokens, string $title, string $body, array $data = []): array
    {
        if (empty($tokens)) return [];

        $invalidTokens = [];
        foreach ($tokens as $token) {
            $ok = $this->send($token, $title, $body, $data);
            if (!$ok) $invalidTokens[] = $token;
        }
        return $invalidTokens;
    }
}
