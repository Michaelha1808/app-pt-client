<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;

/**
 * Cấu hình public cho frontend (không cần auth): feature flags do admin bật/tắt
 * runtime trong Settings — FE dùng để ẩn/hiện nút OAuth, chế độ khách, đăng ký…
 * Chỉ trả về flag boolean, tuyệt đối không lộ secret.
 */
class AppConfigController extends Controller
{
    public function index(SettingsService $settings): JsonResponse
    {
        return response()->json([
            'features' => [
                'registration_open'  => $settings->get('features.registration_open', true) === true,
                'guest_mode_enabled' => $settings->get('features.guest_mode_enabled', true) === true,
                'maintenance_mode'   => $settings->get('features.maintenance_mode', false) === true,
            ],
            'oauth' => [
                'google_enabled'   => $settings->get('oauth.google_enabled', true) === true,
                'facebook_enabled' => $settings->get('oauth.facebook_enabled', true) === true,
            ],
            'ai' => [
                'food_analysis_enabled' => $settings->get('ai.food_analysis_enabled', true) === true,
                'chat_enabled'          => $settings->get('ai.chat_enabled', true) === true,
            ],
        ]);
    }
}
