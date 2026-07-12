<?php

namespace App\Providers;

use App\Services\SettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->applyMailSettings();
        $this->configureRateLimiting();
    }

    /**
     * Ghi đè mail.from bằng giá trị admin cấu hình trong Settings (nếu có).
     * Bọc try/catch: lúc migrate/install DB chưa sẵn sàng → dùng config mặc định.
     */
    private function applyMailSettings(): void
    {
        try {
            $settings = $this->app->make(SettingsService::class);
            if ($from = $settings->get('mail.from_address')) {
                config(['mail.from.address' => $from]);
            }
            if ($name = $settings->get('mail.from_name')) {
                config(['mail.from.name' => $name]);
            }
        } catch (\Throwable) {
            // DB chưa sẵn sàng — giữ config/env mặc định.
        }
    }

    /**
     * Rate limiter động cho các endpoint gọi AI — số req/phút do admin cấu hình
     * runtime trong Settings (rate_limit.*), không cần deploy lại.
     * Key theo user id (đã đăng nhập) hoặc IP (khách).
     */
    private function configureRateLimiting(): void
    {
        $perMinute = function (string $key, int $fallback, Request $request): Limit {
            $max = (int) app(SettingsService::class)->get($key, $fallback);
            return Limit::perMinute(max(1, $max))
                ->by($request->user('sanctum')?->id ?: $request->ip());
        };

        RateLimiter::for('food-analyze', fn (Request $r) => $perMinute('rate_limit.food_analyze_per_min', 10, $r));
        RateLimiter::for('chat', fn (Request $r) => $perMinute('rate_limit.chat_per_min', 15, $r));
        RateLimiter::for('plan-generate', fn (Request $r) => $perMinute('rate_limit.plan_generate_per_min', 5, $r));
    }
}
