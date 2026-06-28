<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\FoodController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\IntegrationWebhookController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\StreakController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WaterController;
use App\Http\Controllers\Api\V1\WebAuthnController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    Route::get('/google', [AuthController::class, 'googleRedirect']);
    Route::get('/google/callback', [AuthController::class, 'googleCallback']);

    Route::get('/facebook', [AuthController::class, 'facebookRedirect']);
    Route::get('/facebook/callback', [AuthController::class, 'facebookCallback']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

// Food analysis — public (guest được phép), rate limit 10/min
Route::middleware('throttle:10,1')->post('/food/analyze', [FoodController::class, 'analyze']);

// Multi-dish detect — public (guest được phép), rate limit 10/min
Route::middleware('throttle:10,1')->post('/food/detect', [FoodController::class, 'detect']);

// Nhận xét AI cho cả bữa (SSE) — public, rate limit 10/min
Route::middleware('throttle:10,1')->post('/food/advise-meal', [FoodController::class, 'adviseMeal']);

// Food log — auth required
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/food/log', [FoodController::class, 'log']);
    Route::post('/food/log-batch', [FoodController::class, 'logBatch']);
    Route::delete('/food/log/{log}', [FoodController::class, 'deleteLog']);
    Route::get('/food/today', [FoodController::class, 'todayStats']);
    Route::get('/food/history', [FoodController::class, 'history']);
});

// Kế hoạch ăn uống & tập luyện (AI) — auth required
Route::middleware('auth:sanctum')->prefix('plan')->group(function () {
    Route::get('/', [PlanController::class, 'show']);
    Route::get('/history', [PlanController::class, 'history']);
    Route::middleware('throttle:5,1')->post('/generate', [PlanController::class, 'generate']);
});

// Passkey / WebAuthn (vân tay, Face ID)
Route::prefix('webauthn')->group(function () {
    // Đăng nhập bằng passkey — công khai
    Route::middleware('throttle:10,1')->post('/login/options', [WebAuthnController::class, 'loginOptions']);
    Route::middleware('throttle:10,1')->post('/login/verify', [WebAuthnController::class, 'loginVerify']);
    // Đăng ký / quản lý passkey — yêu cầu đăng nhập
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/register/options', [WebAuthnController::class, 'registerOptions']);
        Route::post('/register/verify', [WebAuthnController::class, 'registerVerify']);
        Route::get('/status', [WebAuthnController::class, 'status']);
        Route::delete('/', [WebAuthnController::class, 'disable']);
    });
});

// AI chat tư vấn — cho phép khách (quota client-side), rate limit 15/min
// User đăng nhập gửi kèm Bearer token → có ngữ cảnh cá nhân hóa; khách → tư vấn chung.
Route::middleware('throttle:15,1')->post('/chat', [ChatController::class, 'send']);

Route::middleware('auth:sanctum')->prefix('notifications')->group(function () {
    Route::post('/subscribe', [NotificationController::class, 'subscribe']);
    Route::delete('/subscribe', [NotificationController::class, 'unsubscribe']);
    Route::get('/settings', [NotificationController::class, 'getSettings']);
    Route::put('/settings', [NotificationController::class, 'updateSettings']);
    Route::get('/history', [NotificationController::class, 'history']);
    Route::patch('/read-all', [NotificationController::class, 'markAllRead']);
    Route::patch('/{notificationLog}/read', [NotificationController::class, 'markRead']);
    Route::post('/test', [NotificationController::class, 'sendTest']);
});

Route::middleware('auth:sanctum')->prefix('streak')->group(function () {
    Route::get('/',       [StreakController::class, 'show']);
    Route::post('/freeze', [StreakController::class, 'useFreeze']);
});

Route::middleware('auth:sanctum')->prefix('water')->group(function () {
    Route::get('/today',        [WaterController::class, 'today']);
    Route::post('/log',         [WaterController::class, 'log']);
    Route::delete('/log/{waterLog}', [WaterController::class, 'delete']);
});

// ── Tích hợp app sức khoẻ (Strava…) + log buổi tập thủ công ──
// Callback OAuth + webhook: route PUBLIC (provider gọi vào, không có Sanctum token).
Route::middleware('throttle:30,1')->get('/integrations/{provider}/callback', [IntegrationController::class, 'callback']);
Route::get('/webhooks/{provider}', [IntegrationWebhookController::class, 'verify']);
Route::post('/webhooks/{provider}', [IntegrationWebhookController::class, 'receive']);

Route::middleware('auth:sanctum')->prefix('integrations')->group(function () {
    Route::get('/', [IntegrationController::class, 'index']);
    Route::get('/activities', [IntegrationController::class, 'activities']);
    Route::middleware('throttle:30,1')->post('/activities/manual', [IntegrationController::class, 'storeManual']);
    Route::delete('/activities/{activity}', [IntegrationController::class, 'destroyManual']);
    // Connect/disconnect provider (đặt SAU activities để không nuốt path 'activities')
    Route::middleware('throttle:10,1')->get('/{provider}/connect', [IntegrationController::class, 'connect']);
    Route::delete('/{provider}', [IntegrationController::class, 'disconnect']);
});

Route::middleware('auth:sanctum')->prefix('user')->group(function () {
    Route::get('/profile', [UserController::class, 'profile']);
    Route::patch('/profile', [UserController::class, 'updateProfile']);
    Route::post('/avatar', [UserController::class, 'uploadAvatar']);
    Route::delete('/avatar', [UserController::class, 'deleteAvatar']);
    Route::post('/change-password', [UserController::class, 'changePassword']);
});

// ── Admin (yêu cầu role = admin) ──
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::get('/stats', [\App\Http\Controllers\Api\V1\Admin\StatsController::class, 'index']);

    Route::get('/users', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'index']);
    Route::get('/users/{user}', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'show']);
    Route::patch('/users/{user}', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'update']);
    Route::post('/users/{user}/suspend', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'suspend']);
    Route::post('/users/{user}/restore', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'restore']);
    Route::post('/users/{user}/reset-password', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'resetPassword']);
    Route::delete('/users/{user}', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'destroy']);

    Route::get('/settings', [\App\Http\Controllers\Api\V1\Admin\SettingsController::class, 'index']);
    Route::put('/settings', [\App\Http\Controllers\Api\V1\Admin\SettingsController::class, 'update']);
    Route::post('/settings/test/{service}', [\App\Http\Controllers\Api\V1\Admin\SettingsController::class, 'test']);

    Route::get('/audit-logs', [\App\Http\Controllers\Api\V1\Admin\AuditLogController::class, 'index']);

    Route::get('/notifications', [\App\Http\Controllers\Api\V1\Admin\NotificationController::class, 'index']);
    Route::post('/notifications/preview', [\App\Http\Controllers\Api\V1\Admin\NotificationController::class, 'preview']);
    Route::post('/notifications', [\App\Http\Controllers\Api\V1\Admin\NotificationController::class, 'send']);
});
