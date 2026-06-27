<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Đọc/ghi cấu hình runtime lưu ở bảng `settings` (key dạng "group.name").
 * Có cache toàn bộ; fallback an toàn khi bảng chưa tồn tại (vd lúc migrate).
 */
class SettingsService
{
    private const CACHE_KEY = 'settings.all';

    /** Cấu hình mặc định + nguồn fallback từ config()/env(). */
    public function defaults(): array
    {
        return [
            'ai' => [
                'provider'              => 'gemini',
                'model'                 => config('services.gemini.model', 'gemini-2.0-flash'),
                'api_key'               => config('services.gemini.key'),
                'temperature'           => 0.4,
                'max_tokens'            => 2048,
                'food_analysis_enabled' => true,
                'chat_enabled'          => true,
            ],
            'rate_limit' => [
                'food_analyze_per_min'  => 10,
                'chat_per_min'          => 15,
                'plan_generate_per_min' => 5,
            ],
            'notifications' => [
                'fcm_enabled'      => true,
                'fcm_project_id'   => config('firebase.projects.app.project_id') ?? env('FIREBASE_PROJECT_ID'),
                'morning_default'  => '07:00',
                'evening_default'  => '21:00',
                'reengagement_days' => 7,
            ],
            'mail' => [
                'from_address'         => config('mail.from.address'),
                'from_name'            => config('mail.from.name'),
                'reengagement_enabled' => true,
            ],
            'oauth' => [
                'google_enabled'   => (bool) config('services.google.client_id'),
                'facebook_enabled' => (bool) config('services.facebook.client_id'),
            ],
            'features' => [
                'registration_open'  => true,
                'guest_mode_enabled' => true,
                'maintenance_mode'   => false,
            ],
        ];
    }

    /** Các key chứa secret — không bao giờ trả raw ra API. */
    public function secretKeys(): array
    {
        return ['ai.api_key'];
    }

    /**
     * Lấy 1 giá trị theo key "group.name". Ưu tiên DB → fallback defaults.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->allRaw();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        [$group, $name] = array_pad(explode('.', $key, 2), 2, null);
        $defaults = $this->defaults();
        if ($name !== null && isset($defaults[$group]) && array_key_exists($name, $defaults[$group])) {
            return $defaults[$group][$name];
        }

        return $default;
    }

    /** Ghi 1 giá trị + flush cache. */
    public function set(string $key, mixed $value): void
    {
        [$group] = explode('.', $key, 2);
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => $group],
        );
        Cache::forget(self::CACHE_KEY);
    }

    /** Ghi nhiều giá trị (mảng phẳng key => value). */
    public function setMany(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            [$group] = explode('.', $key, 2);
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);
        }
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Toàn bộ cấu hình gom theo group (DB override defaults).
     * @param bool $maskSecrets Mask các secret key khi true (dùng cho API).
     */
    public function all(bool $maskSecrets = false): array
    {
        $result = $this->defaults();
        foreach ($this->allRaw() as $key => $value) {
            [$group, $name] = array_pad(explode('.', $key, 2), 2, null);
            if ($name !== null) {
                $result[$group][$name] = $value;
            }
        }

        if ($maskSecrets) {
            foreach ($this->secretKeys() as $sk) {
                [$group, $name] = array_pad(explode('.', $sk, 2), 2, null);
                if ($name !== null && isset($result[$group])) {
                    $result[$group][$name] = $this->mask($result[$group][$name] ?? null);
                }
            }
        }

        return $result;
    }

    /** Che giá trị secret: giữ vài ký tự đầu/cuối. */
    public function mask(?string $value): ?string
    {
        if (! $value) return null;
        $len = strlen($value);
        if ($len <= 8) return str_repeat('•', $len);
        return substr($value, 0, 4) . str_repeat('•', 8) . substr($value, -3);
    }

    /** Mảng phẳng key => value lấy từ DB (có cache). */
    private function allRaw(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            if (! Schema::hasTable('settings')) {
                return [];
            }
            return Setting::query()->pluck('value', 'key')->toArray();
        });
    }
}
