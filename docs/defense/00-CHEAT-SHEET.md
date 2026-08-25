# CHEAT SHEET — Bản đồ Feature → File

> In ra A4, cầm khi bảo vệ. Thầy hỏi → dò cột "Thầy nói" → mở file → sửa.

---

## Bảng 1 — Feature user thấy → File cần mở

| Feature user thấy | Vue file (UI) | Backend route | Service/logic PHP | DB table |
|---|---|---|---|---|
| Trang chủ (dashboard) | `pages/Home.vue` | `GET /food/today`, `GET /home/daily-tasks` | `FoodController::todayStats` | `meal_logs`, `water_logs`, `user_streaks` |
| Đăng ký | `pages/auth/Register.vue` | `POST /auth/register` | `AuthController::register` | `users` |
| Đăng nhập | `pages/auth/Login.vue` | `POST /auth/login` | `AuthController::login` | `users` |
| Đăng nhập Google | `pages/auth/Login.vue` + `Callback.vue` | `GET /auth/google` → `/auth/google/callback` | `AuthController::googleRedirect / googleCallback` | `users` |
| Đăng nhập vân tay | `pages/auth/Login.vue` + `composables/usePasskey.ts` | `POST /auth/webauthn/*` | `WebAuthnController` | `webauthn_credentials` |
| Chụp ảnh (Scan) | `pages/Scan.vue` | — | — | — |
| Kết quả phân tích 1 món | `pages/Result.vue` + `composables/useFoodAnalysis.ts` | `POST /food/analyze` | `FoodAnalysisService::getStructuredData` + `DishCatalogService::groundOne` | `meal_logs`, `dishes` |
| Chọn món (nhận diện nhiều món) | `pages/MealPicker.vue` + `composables/useFoodDetect.ts` | `POST /food/detect` | `FoodAnalysisService::detectDishes` + `DishCatalogService::ground` | `meal_logs`, `dishes`, `food_detection_samples` |
| Popup sửa món | `components/food/FoodEditSheet.vue` | `POST /food/estimate` | `FoodAnalysisService::estimateNutrition` (fallback nếu không khớp `dishes`) | — |
| Lời khuyên AI dưới món | `pages/Result.vue` (Streaming) | `POST /food/advise` | `FoodAnalysisService::streamAdvice` | — |
| Ghi nhanh (quick log) | `pages/Home.vue` + `composables/useMealLog.ts` | `POST /food/log`, `/food/log-batch` | `FoodController::log`, `QuickLogService` | `meal_logs` |
| Lịch sử ăn uống | `pages/History.vue` | `GET /food/history`, `/food/timeline` | `FoodController::history` | `meal_logs`, `health_activities` |
| Món yêu thích | `pages/profile/Favorites.vue` | `GET/POST /food/favorites` | `FavoriteController` | `favorite_meals` |
| Kế hoạch bữa ăn AI | `pages/MealPlan.vue` + `composables/useMealPlan.ts` | `POST /plans/{scope}/generate`, `/plans/apply` | `MealPlanService::getStructuredPlan` | `meal_plans` |
| Tiến độ kế hoạch | `pages/PlanProgress.vue` | `GET /plans/progress` | `PlanProgressService` | `meal_plans`, `meal_logs` |
| Chat AI dinh dưỡng | `pages/Chat.vue` + `composables/useChat.ts` | `POST /chat/stream` | `ChatService::stream` | `chat_conversations`, `chat_messages`, `chat_prompt_logs` |
| Lịch sử chat | `pages/ChatHistory.vue`, `ChatHistoryDetail.vue` | `GET/DELETE /chat-history/*` | `ChatHistoryController` | `chat_conversations`, `chat_messages` |
| Chuẩn dinh dưỡng (BMR/TDEE/macros) | Register step 3 + `components/common/AdvisorySource.vue` | `POST /nutrition/calculate`, `GET /nutrition/standards` | `NutritionController` + `Support/NutritionStandard` | `users.activity_level` |
| Cân nặng | `pages/Weight.vue` | `POST /weight/log`, `/weight/apply-goal` | `WeightController` + `WeightService` | `weight_logs`, `users.calorie_goal` |
| Streak | Hiển thị ở `Home.vue` | Tự động (không gọi thủ công) | `StreakService::updateOnMealLog` | `user_streaks`, `streak_milestones` |
| Nước uống | `pages/Home.vue` (block nước) | `POST /water/log`, `GET /water/today` | `WaterController` | `water_logs` |
| Sở thích / dị ứng | `pages/profile/Preferences.vue` | `GET/POST/DELETE /preferences` | `PreferenceService` | `user_preferences` |
| Tích hợp Strava | `pages/integrations/*` | `GET/DELETE /integrations/*` | `Services/Health/StravaProvider` + `HealthActivityWriter` | `health_connections`, `health_activities` |
| Hoạt động thể thao | `pages/Activities.vue` | `GET /integrations/activities` | `IntegrationController::activities` | `health_activities` |
| Notification (push/email) | `pages/settings/*` | `GET/PUT /notifications/settings` | `NotificationController` + `Console/Commands/Notifications/*` | `notification_subscriptions`, `notification_logs` |
| Chia sẻ bữa ăn | `pages/Profile.vue` share button + `components/share/ShareMealSheet.vue` | — | Client-side Web Share API | — |
| Trang cá nhân | `pages/Profile.vue`, `pages/profile/*` | `GET/POST /users/profile`, `/users/change-password` | `UserController` | `users` |
| **Admin — Món chuẩn** | `pages/admin/Dishes.vue` | `GET/POST/PUT/DELETE /admin/dishes/*` | `Admin/DishController` | `dishes` |
| **Admin — User** | `pages/admin/Users.vue` | `GET/POST/DELETE /admin/users/*` | `Admin/UserController` | `users`, `personal_access_tokens` |
| **Admin — Notifications** | `pages/admin/Notifications.vue` | `/admin/notifications/*` | `Admin/NotificationController` | `notification_campaigns` |
| **Admin — Cài đặt runtime** (feature flag, rate limit, giờ nhắc) | `pages/admin/Settings.vue` | `GET/PUT /admin/settings` | `Admin/SettingsController` + `SettingsService` | `settings` |
| **Admin — Audit log** | `pages/admin/AuditLogs.vue` | `GET /admin/audit-logs` | `Admin/AuditLogController` | `admin_audit_logs` |
| **Admin — Chat logs** | `pages/admin/ChatLogs.vue` | `GET /admin/chat-logs` | `Admin/ChatLogController` | `chat_prompt_logs` |

---

## Bảng 2 — "Thầy đòi sửa X" → File + hằng số

| Thầy đòi sửa | File | Chỗ cần đổi |
|---|---|---|
| Công thức BMR | `app/Support/NutritionStandard.php` | `bmr()` (Mifflin-St Jeor) |
| Hệ số PAL / TDEE | `app/Support/NutritionStandard.php` | hằng `PAL` (5 mức) |
| Tỉ lệ macro P/C/F | `app/Support/NutritionStandard.php` | hằng `MACRO_RATIO` (0.15 / 0.55 / 0.30) |
| Điều chỉnh calo giảm/tăng cân | `app/Support/NutritionStandard.php` | `GOAL_ADJUSTMENT` (−500 / 0 / +300) |
| Sàn calo tối thiểu | `app/Support/NutritionStandard.php` | `MIN_CALORIES` (male 1500, female 1200) |
| Nước ml/kg | `app/Support/NutritionStandard.php` | `WATER_ML_PER_KG` (35) |
| Ngưỡng khớp thư viện món (fuzzy) | `app/Services/DishCatalogService.php` | `FUZZY_THRESHOLD = 88.0` |
| Ngưỡng cảnh báo macro/kcal lệch | `app/Support/NutritionValidator.php` | `max(50, calories * 0.20)` |
| Tuổi cho phép đăng ký | `app/Http/Controllers/Api/V1/AuthController.php` | `birth_year between:1900,2015` |
| Tối thiểu mật khẩu | `app/Http/Controllers/Api/V1/AuthController.php` | `password min:8` |
| Chiều cao / cân nặng min-max | `app/Http/Controllers/Api/V1/AuthController.php` | `height_cm between:50,300`, `weight_kg between:20,500` |
| Rate limit AI (per phút) | `bootstrap/app.php` (RateLimiter) hoặc runtime qua `Admin/Settings` | `rate_limit.food_analyze_per_min`, `rate_limit.chat_per_min`, `rate_limit.plan_generate_per_min` |
| Timeout gọi Gemini | `app/Services/FoodAnalysisService.php` constructor | `'timeout' => 30` |
| Prompt AI phân tích món | `app/Services/FoodAnalysisService.php` | heredoc `PROMPT` trong `getStructuredData`, `detectDishes`, `estimateNutrition` |
| Prompt AI kế hoạch | `app/Services/MealPlanService.php` | `dailyPrompt`, `weeklyPrompt`, `monthlyPrompt` |
| Prompt AI chat | `app/Services/ChatService.php` | `buildUserContext`, `stream` (systemInstruction) |
| Prompt AI grounding chuẩn VDD | `app/Support/NutritionStandard.php` | `promptStandardsBlock()` |
| Bộ món chuẩn (seed) — thêm/sửa món | `database/seeders/DishCatalogSeeder.php` | array `$rows`. Sau khi sửa: `php artisan db:seed --class=DishCatalogSeeder --force` |
| Màu chủ đạo (calor-green, calor-mint…) | `resources/css/app.css` | biến `--color-calor-*` |
| Config Tailwind | `vite.config.js`, không có `tailwind.config.js` riêng (Tailwind v4 dùng CSS) | — |
| Text logo / brand | `pages/auth/Login.vue`, `pages/auth/Register.vue` | dòng chứa "Calor" và "Eye" |
| Nhãn OAuth Google/Facebook | `pages/auth/Login.vue`, `Register.vue` | `aria-label`, text bên nút |
| Guest mode default | Admin/Settings hoặc `AppConfigController.php` | `features.guest_mode_enabled` |

---

## Bảng 3 — Nơi lưu prompt AI (để đọc/edit khi thầy hỏi "sao AI trả lời vậy?")

| Chức năng AI | File | Method |
|---|---|---|
| Phân tích 1 món (image → calo) | `app/Services/FoodAnalysisService.php` | `getStructuredData` |
| Nhận diện nhiều món | `app/Services/FoodAnalysisService.php` | `detectDishes` |
| Ước tính calo khi user sửa tên | `app/Services/FoodAnalysisService.php` | `estimateNutrition` |
| Lời khuyên món (streaming) | `app/Services/FoodAnalysisService.php` | `streamAdvice` |
| Nhận xét cả bữa | `app/Services/FoodAnalysisService.php` | `streamMealAdvice` |
| Kế hoạch ngày mai | `app/Services/MealPlanService.php` | `dailyPrompt` |
| Kế hoạch tuần | `app/Services/MealPlanService.php` | `weeklyPrompt` |
| Kế hoạch tháng | `app/Services/MealPlanService.php` | `monthlyPrompt` |
| Chat context (BMR/TDEE/today) | `app/Services/ChatService.php` | `buildUserContext` |
| Chat streaming | `app/Services/ChatService.php` | `stream` |

---

## Ghi chú nhanh khi bảo vệ

- **Auto-imports**: Vue/router/Pinia + mọi thứ trong `composables/`, `utils/`, `stores/` **không cần import thủ công** (`unplugin-auto-import` đã cấu hình). Nếu thấy Claude sinh `import { ref }` là biết dư — không cần fix, không phá.
- **Sanctum**: mọi endpoint có `auth:sanctum` → cần login. Guest access: `/food/analyze`, `/food/detect`, `/chat/stream` (chỉ 1 số cái).
- **Timezone**: mọi thứ luôn `Asia/Ho_Chi_Minh` (config trong `.env`).
- **Test**: `php artisan test` chạy trên SQLite in-memory — không cần DB thật. `php artisan test --filter=<Tên>` để chạy 1 test.
- **Format PHP**: `vendor/bin/pint` (không dùng Prettier cho PHP).
- **Nếu FE không nhận đổi mới**: `Ctrl+F5` để bypass service worker cache (PWA).
