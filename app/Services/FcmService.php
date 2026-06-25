<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FcmService
{
    /** Gửi thành công. */
    private const SENT = 'sent';
    /** Token hỏng vĩnh viễn (gỡ app / sai định dạng) → nên xoá khỏi DB. */
    private const INVALID = 'invalid';
    /** Lỗi tạm thời (mạng / server Google / quota / config) → GIỮ token, thử lại sau. */
    private const ERROR = 'error';

    public function __construct(private Messaging $messaging) {}

    /**
     * Gửi push tới một token.
     * Trả về true nếu gửi thành công, false nếu không (vì bất kỳ lý do gì).
     *
     * ⚠️ Không dùng giá trị false để quyết định xoá token — một lỗi tạm thời
     * cũng trả về false. Muốn xoá token an toàn, dùng sendMulticast().
     */
    public function send(string $token, string $title, string $body, array $data = []): bool
    {
        return $this->sendToToken($token, $title, $body, $data) === self::SENT;
    }

    /**
     * Gửi tới nhiều tokens. Chỉ trả về những token THẬT SỰ hỏng (cần xoá khỏi DB).
     * Token gặp lỗi tạm thời sẽ KHÔNG nằm trong danh sách trả về — tránh xoá nhầm
     * token hợp lệ khi Google lỗi / mất mạng / sai cấu hình.
     *
     * @return string[] Danh sách token cần xoá.
     */
    public function sendMulticast(array $tokens, string $title, string $body, array $data = []): array
    {
        if (empty($tokens)) return [];

        $invalidTokens = [];
        foreach ($tokens as $token) {
            if ($this->sendToToken($token, $title, $body, $data) === self::INVALID) {
                $invalidTokens[] = $token;
            }
        }

        return $invalidTokens;
    }

    /**
     * Gửi tới 1 token và phân loại kết quả: sent | invalid | error.
     */
    private function sendToToken(string $token, string $title, string $body, array $data = []): string
    {
        try {
            $message = CloudMessage::new()
                ->withNotification(Notification::create($title, $body))
                ->withData($data)
                ->toToken($token);

            $this->messaging->send($message);
            return self::SENT;
        } catch (NotFound | InvalidMessage | InvalidArgument $e) {
            // Token đã bị huỷ đăng ký hoặc sai định dạng → không bao giờ gửi được → xoá.
            return self::INVALID;
        } catch (\Throwable $e) {
            // Auth / server Google / quota / mạng / cấu hình → lỗi tạm thời.
            // KHÔNG xoá token; chỉ log để theo dõi.
            Log::warning('[FCM] Gửi thất bại (giữ token)', [
                'token' => substr($token, 0, 12) . '…',
                'type'  => class_basename($e),
                'error' => $e->getMessage(),
            ]);
            return self::ERROR;
        }
    }
}
