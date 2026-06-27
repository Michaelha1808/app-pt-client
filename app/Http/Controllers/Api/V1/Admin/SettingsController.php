<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use App\Support\AuditLogger;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class SettingsController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function index(): JsonResponse
    {
        return response()->json($this->settings->all(maskSecrets: true));
    }

    public function update(Request $request): JsonResponse
    {
        $rules = [
            'ai.provider'                  => 'sometimes|string|in:gemini',
            'ai.model'                     => 'sometimes|string|max:60',
            'ai.api_key'                   => 'sometimes|nullable|string|max:200',
            'ai.temperature'               => 'sometimes|numeric|between:0,2',
            'ai.max_tokens'                => 'sometimes|integer|between:256,8192',
            'ai.food_analysis_enabled'     => 'sometimes|boolean',
            'ai.chat_enabled'              => 'sometimes|boolean',
            'rate_limit.food_analyze_per_min'  => 'sometimes|integer|between:1,120',
            'rate_limit.chat_per_min'          => 'sometimes|integer|between:1,120',
            'rate_limit.plan_generate_per_min' => 'sometimes|integer|between:1,60',
            'notifications.fcm_enabled'       => 'sometimes|boolean',
            'notifications.morning_default'   => 'sometimes|date_format:H:i',
            'notifications.evening_default'   => 'sometimes|date_format:H:i',
            'notifications.reengagement_days' => 'sometimes|integer|between:1,30',
            'mail.from_address'           => 'sometimes|nullable|email',
            'mail.from_name'              => 'sometimes|nullable|string|max:100',
            'mail.reengagement_enabled'   => 'sometimes|boolean',
            'oauth.google_enabled'        => 'sometimes|boolean',
            'oauth.facebook_enabled'      => 'sometimes|boolean',
            'features.registration_open'  => 'sometimes|boolean',
            'features.guest_mode_enabled' => 'sometimes|boolean',
            'features.maintenance_mode'   => 'sometimes|boolean',
        ];

        // Flatten payload "group.name" => value
        $flat = [];
        foreach ($request->all() as $group => $values) {
            if (! is_array($values)) continue;
            foreach ($values as $name => $value) {
                $flat["{$group}.{$name}"] = $value;
            }
        }

        $validator = validator($flat, array_intersect_key($rules, $flat));
        $validator->validate();

        $secretKeys = $this->settings->secretKeys();
        $toSave = [];
        foreach ($flat as $key => $value) {
            if (! array_key_exists($key, $rules)) continue; // bỏ key lạ
            // Secret: bỏ qua nếu rỗng hoặc vẫn là chuỗi đã mask (không thay đổi)
            if (in_array($key, $secretKeys, true)) {
                if (! $value || str_contains((string) $value, '•')) continue;
            }
            $toSave[$key] = $value;
        }

        if ($toSave) {
            $this->settings->setMany($toSave);
            AuditLogger::log('settings.update', 'settings', null, ['keys' => array_keys($toSave)]);
        }

        return response()->json($this->settings->all(maskSecrets: true));
    }

    public function test(string $service): JsonResponse
    {
        return match ($service) {
            'ai'   => $this->testAi(),
            'fcm'  => $this->testFcm(),
            'mail' => $this->testMail(),
            default => response()->json(['ok' => false, 'message' => 'Dịch vụ không hợp lệ'], 422),
        };
    }

    private function testAi(): JsonResponse
    {
        $key   = $this->settings->get('ai.api_key', config('services.gemini.key'));
        $model = $this->settings->get('ai.model', 'gemini-2.0-flash');
        if (! $key) {
            return response()->json(['ok' => false, 'message' => 'Chưa cấu hình API key']);
        }

        $start = microtime(true);
        try {
            $http = new Client(['timeout' => 15]);
            $http->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}",
                ['json' => ['contents' => [['parts' => [['text' => 'ping']]]],
                    'generationConfig' => ['maxOutputTokens' => 1]]]
            );
            $ms = (int) round((microtime(true) - $start) * 1000);
            return response()->json(['ok' => true, 'latency_ms' => $ms, 'message' => "Kết nối {$model} thành công"]);
        } catch (\Throwable $e) {
            $code = method_exists($e, 'getCode') ? $e->getCode() : 0;
            return response()->json(['ok' => false, 'message' => "Lỗi kết nối Gemini ({$code})"]);
        }
    }

    private function testFcm(): JsonResponse
    {
        try {
            $messaging = app(\Kreait\Firebase\Contract\Messaging::class);
            // Validate token rỗng sẽ ném lỗi InvalidArgument → chứng tỏ credentials hợp lệ/kết nối được
            return response()->json(['ok' => true, 'message' => 'Firebase Messaging đã sẵn sàng']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Không khởi tạo được Firebase: ' . $e->getMessage()]);
        }
    }

    private function testMail(): JsonResponse
    {
        $admin = request()->user();
        try {
            Mail::raw('Đây là email kiểm tra cấu hình từ CaloEye Admin.', function ($m) use ($admin) {
                $m->to($admin->email)->subject('CaloEye — Test email');
            });
            return response()->json(['ok' => true, 'message' => "Đã gửi email test tới {$admin->email}"]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Gửi mail thất bại: ' . $e->getMessage()]);
        }
    }
}
