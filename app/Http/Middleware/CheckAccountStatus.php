<?php

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAccountStatus
{
    public function __construct(private SettingsService $settings) {}

    /**
     * - Chặn user bị khoá (suspended) trên mọi API (trừ logout để FE tự dọn phiên).
     * - Chặn toàn bộ API non-admin khi bật maintenance_mode.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Maintenance mode — admin vẫn truy cập được
        if ($this->settings->get('features.maintenance_mode', false) === true) {
            if (! $user || ! $user->isAdmin()) {
                // /health và /config vẫn mở để FE biết trạng thái hệ thống khi bảo trì
                if (! $request->is('api/*/health', 'api/health', 'api/*/config')) {
                    return response()->json([
                        'detail' => 'Hệ thống đang bảo trì, vui lòng quay lại sau.',
                        'code'   => 'maintenance',
                    ], 503);
                }
            }
        }

        // Tài khoản bị khoá
        if ($user && $user->isSuspended() && ! $request->is('*/auth/logout')) {
            return response()->json([
                'detail' => 'Tài khoản của bạn đã bị khoá.',
                'code'   => 'account_suspended',
            ], 403);
        }

        return $next($request);
    }
}
