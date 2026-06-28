<?php

namespace App\Services\Health;

use App\Models\HealthConnection;
use Illuminate\Http\Request;

/**
 * Hợp đồng chung cho mọi provider sức khoẻ (Strava, Fitbit, Garmin…).
 * Thêm provider mới = implement interface này + đăng ký trong HealthProviderFactory,
 * không phải sửa controller/job/core.
 */
interface HealthProvider
{
    /** Tên provider (vd 'strava'). */
    public function key(): string;

    /** URL để redirect user sang trang đồng ý cấp quyền. $state đã ký để chống CSRF. */
    public function authorizeUrl(string $state): string;

    /** Đổi authorization code → bộ token (access + refresh + expiry + athlete id). */
    public function exchangeCode(string $code): TokenSet;

    /** Làm mới access token bằng refresh token. */
    public function refresh(string $refreshToken): TokenSet;

    /** Lấy chi tiết 1 activity, trả mảng ĐÃ CHUẨN HOÁ theo cột health_activities. */
    public function fetchActivity(HealthConnection $connection, string $externalId): ?array;

    /** Lấy N activity gần nhất (đã chuẩn hoá) để backfill. */
    public function fetchRecentActivities(HealthConnection $connection, int $limit): array;

    /** Đăng ký webhook subscription, trả về webhook_id (null nếu provider không hỗ trợ). */
    public function registerWebhook(): ?string;

    /** Xác minh handshake webhook (challenge). Trả response array hoặc null nếu không hợp lệ. */
    public function verifyWebhook(Request $request): ?array;

    /** Thu hồi quyền truy cập phía provider (best-effort). */
    public function revoke(HealthConnection $connection): void;
}
