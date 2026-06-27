<?php

namespace App\Support;

use App\Models\AdminAuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    /**
     * Ghi nhật ký một hành động của admin. Không bao giờ làm hỏng luồng chính.
     * Lưu ý: KHÔNG truyền secret vào $meta.
     */
    public static function log(string $action, ?string $targetType = null, ?string $targetId = null, array $meta = []): void
    {
        try {
            AdminAuditLog::create([
                'admin_id'    => Auth::id(),
                'action'      => $action,
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'meta'        => $meta ?: null,
                'ip'          => Request::ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AuditLogger failed: ' . $e->getMessage());
        }
    }
}
