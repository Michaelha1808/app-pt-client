<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\FavoriteController;
use App\Http\Controllers\Api\V1\FoodController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\IntegrationWebhookController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\PreferenceController;
use App\Http\Controllers\Api\V1\StreakController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WaterController;
use App\Http\Controllers\Api\V1\WebAuthnController;
use App\Http\Controllers\Api\V1\WeightController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);

// Feature flags public cho FE (ẩn/hiện OAuth, guest mode, đăng ký…) — không lộ secret
Route::get('/config', [\App\Http\Controllers\Api\V1\AppConfigController::class, 'index']);

// Chuẩn dinh dưỡng: BMR/TDEE/goal/macros/citations (VDD 2016 + WHO/FAO 2001).
// Public — dùng cho register flow để gợi ý calorie_goal cá nhân hoá.
Route::get('/nutrition/standards',   [\App\Http\Controllers\Api\V1\NutritionController::class, 'standards']);
Route::post('/nutrition/calculate',  [\App\Http\Controllers\Api\V1\NutritionController::class, 'calculate']);

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

        Route::post('/email/verify', [AuthController::class, 'verifyEmail']);
        Route::post('/email/resend', [AuthController::class, 'resendVerificationCode']);
    });
});

// Food analysis — public (guest được phép), rate limit động theo Settings (rate_limit.food_analyze_per_min)
Route::middleware('throttle:food-analyze')->post('/food/analyze', [FoodController::class, 'analyze']);

// Multi-dish detect — public (guest được phép), chung quota với food analysis
Route::middleware('throttle:food-analyze')->post('/food/detect', [FoodController::class, 'detect']);

// Ghi nhận kết quả user chốt cho 1 lần detect (dataset cải thiện model) — public, rate limit 20/min
Route::middleware('throttle:20,1')->post('/food/detect/{sample}/feedback', [FoodController::class, 'detectFeedback']);

// Nhận xét AI cho cả bữa (SSE) — public, chung quota với food analysis
Route::middleware('throttle:food-analyze')->post('/food/advise-meal', [FoodController::class, 'adviseMeal']);

// Sinh lại lời khuyên cho 1 món sau khi user sửa tên/calo ở Result.vue (không chạy lại nhận
// diện ảnh) — public, chung quota với food analysis
Route::middleware('throttle:food-analyze')->post('/food/advise', [FoodController::class, 'advise']);

// Ước tính lại calo/macro theo tên món user vừa sửa (JSON, không stream) — chạy trước
// /food/advise để lời khuyên khớp số liệu mới; public, chung quota với food analysis
Route::middleware('throttle:food-analyze')->post('/food/estimate', [FoodController::class, 'estimate']);

// Food log — auth required
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/food/log', [FoodController::class, 'log']);
    Route::post('/food/log-batch', [FoodController::class, 'logBatch']);
    Route::delete('/food/log/{log}', [FoodController::class, 'deleteLog']);
    Route::get('/food/today', [FoodController::class, 'todayStats']);
    Route::get('/food/history', [FoodController::class, 'history']);
    Route::get('/food/timeline', [FoodController::class, 'timeline']);   // gộp ăn + tập theo khoảng ngày

    // Log nhanh: ăn lại món cũ (không gọi AI) + món thường ăn 30 ngày theo khung giờ
    Route::post('/food/relog/{log}', [FoodController::class, 'relog']);
    Route::get('/food/frequent', [FoodController::class, 'frequent']);

    // Món yêu thích
    Route::get('/food/favorites', [FavoriteController::class, 'index']);
    Route::post('/food/favorites', [FavoriteController::class, 'store']);
    Route::post('/food/favorites/{favorite}/log', [FavoriteController::class, 'logFavorite']);
    Route::delete('/food/favorites/{favorite}', [FavoriteController::class, 'destroy']);

    // Nhiệm vụ tập luyện hôm nay theo kế hoạch AI
    Route::get('/home/daily-tasks', [\App\Http\Controllers\Api\V1\DailyTaskController::class, 'index']);
    // "Thực hiện" nhiệm vụ: ghi luôn log ăn/tập đúng như kế hoạch, không cần chờ nhận diện theo giờ.
    Route::post('/home/daily-tasks/complete-meal', [\App\Http\Controllers\Api\V1\DailyTaskController::class, 'completeMeal']);
    Route::post('/home/daily-tasks/complete-workout', [\App\Http\Controllers\Api\V1\DailyTaskController::class, 'completeWorkout']);
});

// Kế hoạch ăn uống & tập luyện (AI) — auth required
Route::middleware('auth:sanctum')->prefix('plan')->group(function () {
    Route::get('/', [PlanController::class, 'show']);
    Route::get('/history', [PlanController::class, 'history']);
    // Tiến độ thực hiện kế hoạch (% hôm nay + tuần này) — đặt TRƯỚC route động nếu có
    Route::get('/progress', [\App\Http\Controllers\Api\V1\PlanProgressController::class, 'index']);
    Route::middleware('throttle:plan-generate')->post('/generate', [PlanController::class, 'generate']);
    // Áp dụng bản nháp vừa sinh (generate chỉ tạo nháp, không tự ghi đè kế hoạch đang dùng)
    Route::post('/apply', [PlanController::class, 'apply']);
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

// AI chat tư vấn — cho phép khách (quota client-side), rate limit động theo Settings (rate_limit.chat_per_min)
// User đăng nhập gửi kèm Bearer token → có ngữ cảnh cá nhân hóa; khách → tư vấn chung.
Route::middleware('throttle:chat')->post('/chat', [ChatController::class, 'send']);

// "Thiết lập kế hoạch ăn hôm nay" từ lời tư vấn — auth required, tốn token AI nên siết chặt.
Route::middleware(['auth:sanctum', 'throttle:plan-generate'])->post('/chat/apply-plan', [ChatController::class, 'applyPlan']);

// Lịch sử chat của user (khác Admin\ChatLogController — đó là log audit nội bộ)
Route::middleware('auth:sanctum')->prefix('chat/conversations')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\V1\ChatHistoryController::class, 'index']);
    Route::get('/{conversation}', [\App\Http\Controllers\Api\V1\ChatHistoryController::class, 'show']);
    Route::delete('/{conversation}', [\App\Http\Controllers\Api\V1\ChatHistoryController::class, 'destroy']);
});

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

// Sở thích / giới hạn ăn uống (bộ nhớ cá nhân hóa của chatbot)
Route::middleware('auth:sanctum')->prefix('preferences')->group(function () {
    Route::get('/', [PreferenceController::class, 'index']);
    Route::middleware('throttle:20,1')->post('/', [PreferenceController::class, 'store']);
    Route::delete('/{id}', [PreferenceController::class, 'destroy']);
});

Route::middleware('auth:sanctum')->prefix('water')->group(function () {
    Route::get('/today',        [WaterController::class, 'today']);
    Route::post('/log',         [WaterController::class, 'log']);
    Route::delete('/log/{waterLog}', [WaterController::class, 'delete']);
});

Route::middleware('auth:sanctum')->prefix('weight')->group(function () {
    Route::post('/log',              [WeightController::class, 'log']);
    Route::get('/history',           [WeightController::class, 'history']);
    Route::delete('/log/{weightLog}', [WeightController::class, 'destroy']);
    Route::post('/apply-goal',       [WeightController::class, 'applyGoal']);
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
    Route::delete('/users/{user}/sessions/{token}', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'revokeSession']);
    Route::delete('/users/{user}', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'destroy']);

    Route::get('/settings', [\App\Http\Controllers\Api\V1\Admin\SettingsController::class, 'index']);
    Route::put('/settings', [\App\Http\Controllers\Api\V1\Admin\SettingsController::class, 'update']);
    Route::post('/settings/test/{service}', [\App\Http\Controllers\Api\V1\Admin\SettingsController::class, 'test']);

    Route::get('/audit-logs', [\App\Http\Controllers\Api\V1\Admin\AuditLogController::class, 'index']);

    // Nhật ký prompt chatbot tư vấn: câu hỏi user + prompt cá nhân hóa cuối cùng gửi Gemini
    Route::get('/chat-logs', [\App\Http\Controllers\Api\V1\Admin\ChatLogController::class, 'index']);
    Route::get('/chat-logs/{chatLog}', [\App\Http\Controllers\Api\V1\Admin\ChatLogController::class, 'show']);

    // Quan trắc hệ thống: info + health check, xoá cache, xem log
    Route::get('/system', [\App\Http\Controllers\Api\V1\Admin\SystemController::class, 'info']);
    Route::post('/system/cache-clear', [\App\Http\Controllers\Api\V1\Admin\SystemController::class, 'clearCache']);
    Route::get('/system/logs', [\App\Http\Controllers\Api\V1\Admin\SystemController::class, 'logs']);

    // Quản lý failed jobs: liệt kê, retry (1 hoặc all), xoá
    Route::get('/system/failed-jobs', [\App\Http\Controllers\Api\V1\Admin\SystemController::class, 'failedJobs']);
    Route::post('/system/failed-jobs/retry', [\App\Http\Controllers\Api\V1\Admin\SystemController::class, 'retryFailedJobs']);
    Route::delete('/system/failed-jobs/{uuid}', [\App\Http\Controllers\Api\V1\Admin\SystemController::class, 'deleteFailedJob']);

    // Thư viện món ăn chuẩn (nutrition DB) — CRUD
    Route::get('/dishes', [\App\Http\Controllers\Api\V1\Admin\DishController::class, 'index']);
    Route::post('/dishes', [\App\Http\Controllers\Api\V1\Admin\DishController::class, 'store']);
    Route::put('/dishes/{dish}', [\App\Http\Controllers\Api\V1\Admin\DishController::class, 'update']);
    Route::delete('/dishes/{dish}', [\App\Http\Controllers\Api\V1\Admin\DishController::class, 'destroy']);

    // Dataset nhận diện (AI đoán vs user sửa) — duyệt + xoá
    Route::get('/dataset/stats', [\App\Http\Controllers\Api\V1\Admin\DatasetController::class, 'stats']);
    Route::get('/dataset', [\App\Http\Controllers\Api\V1\Admin\DatasetController::class, 'index']);
    Route::get('/dataset/{sample}', [\App\Http\Controllers\Api\V1\Admin\DatasetController::class, 'show']);
    Route::delete('/dataset/{sample}', [\App\Http\Controllers\Api\V1\Admin\DatasetController::class, 'destroy']);

    Route::get('/notifications', [\App\Http\Controllers\Api\V1\Admin\NotificationController::class, 'index']);
    Route::post('/notifications/preview', [\App\Http\Controllers\Api\V1\Admin\NotificationController::class, 'preview']);
    Route::post('/notifications', [\App\Http\Controllers\Api\V1\Admin\NotificationController::class, 'send']);
});
