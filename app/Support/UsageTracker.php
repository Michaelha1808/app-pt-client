<?php

namespace App\Support;

use App\Models\UsageEvent;
use Illuminate\Support\Facades\Log;

/**
 * Ghi nhận sự kiện sử dụng (gọi AI...) phục vụ thống kê admin.
 * Tuyệt đối không làm hỏng luồng chính: mọi lỗi đều nuốt + log.
 */
class UsageTracker
{
    public static function record(string $type, ?int $userId = null): void
    {
        try {
            UsageEvent::create([
                'type'       => $type,
                'user_id'    => $userId,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('UsageTracker failed: ' . $e->getMessage());
        }
    }
}
