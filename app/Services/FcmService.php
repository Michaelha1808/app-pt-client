<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\WebPushConfig;

class FcmService
{
    /** Gửi thành công. */
    private const SENT = 'sent';
    /** Token hỏng vĩnh viễn (gỡ app / sai định dạng) → nên xoá khỏi DB. */
    private const INVALID = 'invalid';
    /** Lỗi tạm thời (mạng / server Google / quota / config) → GIỮ token, thử lại sau. */
    private const ERROR = 'error';

    /** TTL (giây) push còn giá trị để hiển thị nếu thiết bị offline — 1 ngày. */
    private const TTL_SECONDS = 86400;

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
        try {
            $this->messaging->send($this->buildMessage($title, $body, $data)->toToken($token));
            return true;
        } catch (NotFound | InvalidMessage | InvalidArgument $e) {
            return false;
        } catch (\Throwable $e) {
            $this->logError($token, $e);
            return false;
        }
    }

    /**
     * Gửi tới nhiều tokens trong MỘT lệnh multicast (nhanh hơn rất nhiều so với
     * gửi tuần tự từng token — quan trọng vào khung giờ nhiều user trùng nhau).
     * Chỉ trả về những token THẬT SỰ hỏng (cần xoá khỏi DB).
     *
     * Token gặp lỗi tạm thời (mạng / server Google / quota) sẽ KHÔNG nằm trong
     * danh sách trả về — tránh xoá nhầm token hợp lệ.
     *
     * @param  string[] $tokens
     * @return string[] Danh sách token cần xoá.
     */
    public function sendMulticast(array $tokens, string $title, string $body, array $data = []): array
    {
        $tokens = array_values(array_unique(array_filter($tokens)));
        if (empty($tokens)) return [];

        try {
            $report = $this->messaging->sendMulticast(
                $this->buildMessage($title, $body, $data),
                $tokens,
            );

            // unknownTokens: app đã gỡ / token hết hạn. invalidTokens: sai định dạng.
            // Cả hai đều không bao giờ gửi được nữa → xoá khỏi DB.
            $toRemove = array_merge($report->unknownTokens(), $report->invalidTokens());

            if ($report->hasFailures()) {
                foreach ($report->failures()->getItems() as $failure) {
                    // Lỗi không phải do token hỏng (auth/quota/mạng) → chỉ log, giữ token.
                    $err = $failure->error();
                    if ($err && !in_array($failure->target()->value(), $toRemove, true)) {
                        Log::warning('[FCM] multicast lỗi tạm thời (giữ token)', [
                            'error' => $err->getMessage(),
                        ]);
                    }
                }
            }

            return array_values(array_unique($toRemove));
        } catch (\Throwable $e) {
            // Toàn bộ batch lỗi (vd cấu hình/credential sai) → KHÔNG xoá token nào.
            $this->logError($tokens[0] ?? '', $e);
            return [];
        }
    }

    /**
     * Tạo message với độ ưu tiên CAO trên mọi nền tảng. Mặc định (normal) bị các
     * push service gom & hoãn khi thiết bị idle lâu (Doze / Low Power qua đêm) →
     * gây miss thông báo buổi sáng và không wake màn khóa. High priority buộc
     * deliver ngay.
     *
     * Vẫn là data-only (title/body trong data) để service worker tự showNotification.
     */
    private function buildMessage(string $title, string $body, array $data): CloudMessage
    {
        return CloudMessage::new()
            ->withData(array_merge($data, ['title' => $title, 'body' => $body]))
            // Web push (PWA Chrome/Android & Safari iOS 16.4+): Urgency=high + TTL.
            ->withWebPushConfig(WebPushConfig::fromArray([
                'headers' => [
                    'Urgency' => 'high',
                    'TTL'     => (string) self::TTL_SECONDS,
                ],
            ]))
            // Android native: priority=high để vượt Doze mode.
            ->withAndroidConfig(AndroidConfig::fromArray([
                'priority' => 'high',
                'ttl'      => self::TTL_SECONDS . 's',
            ]))
            // iOS APNs: priority 10 + push-type alert để hiển thị ngay ở màn khóa.
            ->withApnsConfig(ApnsConfig::fromArray([
                'headers' => [
                    'apns-priority'  => '10',
                    'apns-push-type' => 'alert',
                ],
            ]));
    }

    private function logError(string $token, \Throwable $e): void
    {
        Log::warning('[FCM] Gửi thất bại (giữ token)', [
            'token' => $token ? substr($token, 0, 12) . '…' : '(batch)',
            'type'  => class_basename($e),
            'error' => $e->getMessage(),
        ]);
    }
}
