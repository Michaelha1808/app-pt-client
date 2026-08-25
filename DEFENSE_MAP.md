# DEFENSE_MAP — Bản đồ nghiệp vụ để sửa nhanh

> Tra 2 phút khi hội đồng đòi sửa. Cột trong bảng dùng dạng `path:Lxx` — mở file, đi tới dòng.
> Layer không tồn tại ghi `—`. Layer chưa lần được ghi `⚠ chưa xác định`.

## 0. Cách dùng
1. Đọc §1 để định vị "màn hình thầy hỏi → file cần mở".
2. Nếu là dạng chỉnh sửa thông dụng (text, validation, màu, ngưỡng…) → xem §2 công thức.
3. Trước khi save, kiểm §3 để không vỡ chỗ khác (FE/BE trùng, seeder, PWA cache).
4. Không thấy hiệu lực → §4 xoá cache. Sửa hỏng → §4 rollback.

---

## 1. Bản đồ chức năng

### 1.1 Onboarding & Auth

| # | Chức năng | FE route | Vue page | Composable / call | API | BE route | Controller@method | Service | Model → Table |
|---|---|---|---|---|---|---|---|---|---|
| 1 | Landing | `/` | `pages/Index.vue:L1` | — | — | — | — | — | — |
| 2 | Đăng ký | `/auth/register` | `pages/auth/Register.vue:L1` (call `L220`) | `useAuth.register` `composables/useAuth.ts:L52` | `POST /auth/register` | `routes/api_v1.php:L31` | `AuthController@register` `:L21` | `EmailVerificationService::sendCode` (best-effort) | `User → users` |
| 3 | Đăng nhập | `/auth/login` | `pages/auth/Login.vue:L1` (call `L45`) | `useAuth.login` `useAuth.ts:L45` | `POST /auth/login` | `api_v1.php:L32` | `AuthController@login` `:L72` | — | `User → users` |
| 4 | Đăng nhập Google | `/auth/callback` (nhận token) | `pages/auth/Login.vue` nút (nút `L195`) + `pages/auth/Callback.vue:L1` | `useAuth.loginWithGoogle` `:L87` + `handleOAuthCallback` `:L97` | `GET /auth/google` → callback `GET /auth/google/callback` | `api_v1.php:L37,L38` | `AuthController@googleRedirect` `:L148`, `@googleCallback` `:L160` | Socialite (không có service riêng) | `User → users` |
| 5 | Đăng nhập vân tay (Passkey) | `/auth/login` | `Login.vue` (call `handleBiometricLogin` `L59`) | `usePasskey.loginWithPasskey` `composables/usePasskey.ts` (⚠ chưa xác định dòng chính xác) | `POST /webauthn/login/options`, `POST /webauthn/login/verify` | `api_v1.php:L112,L113` | `WebAuthnController@loginOptions`, `@loginVerify` (⚠ chưa xác định dòng) | Thư viện `lbuchs/webauthn` | `WebauthnCredential → webauthn_credentials` |
| 6 | Quên/Reset mật khẩu | `/auth/forgot-password`, `/auth/reset-password` | `pages/auth/ForgotPassword.vue:L1`, `pages/auth/ResetPassword.vue:L1` | `useAuth.forgotPassword` `:L59`, `useAuth.resetPassword` `:L67` | `POST /auth/forgot-password`, `POST /auth/reset-password` | `api_v1.php:L33,L34` | `AuthController@forgotPassword` `:L265`, `@resetPassword` `:L274` | `EmailVerificationService` (gửi email reset) | `User → users` |

### 1.2 Trang chủ & Nhật ký

| # | Chức năng | FE route | Vue page | Composable / call | API | BE route | Controller@method | Service | Model → Table |
|---|---|---|---|---|---|---|---|---|---|
| 7 | Trang chủ | `/home` | `pages/Home.vue:L1` (5 composables `L24-L30`) | `useMealLog` (todayStats), `useStreak`, `useWater`, `useWeight`, `useQuickLog` | `GET /food/today` + `GET /home/daily-tasks` + `GET /water/today` + `GET /streak` | `api_v1.php:L77`, `L92`, `L161`, `L149` | `FoodController@todayStats` `:L516`, `DailyTaskController@index`, `WaterController@today` `:L12`, `StreakController@show` `:L14` | Nhiều service (StreakService, WeightService) | `meal_logs`, `water_logs`, `user_streaks` |
| 8 | Lịch sử ăn uống | `/history` | `pages/History.vue:L1` (call `L62`) | `useMealLog.timeline` (`L14` destructure), `useHealthIntegration.deleteActivity` | `GET /food/timeline` | `api_v1.php:L79` | `FoodController@timeline` `:L601` | — | `MealLog → meal_logs`, `HealthActivity → health_activities` |

### 1.3 Chụp ảnh & Phân tích món

| # | Chức năng | FE route | Vue page | Composable / call | API | BE route | Controller@method | Service | Model → Table |
|---|---|---|---|---|---|---|---|---|---|
| 9 | Scan (chụp ảnh) | `/scan` | `pages/Scan.vue:L1` | — (chỉ capture, chưa upload) | — | — | — | — | — |
| 10 | Phân tích 1 món + lời khuyên | `/result` | `pages/Result.vue:L1` (analyze `L217`, refetchAdvice `L205`, estimate `L175`) | `useFoodAnalysis.analyze` `composables/useFoodAnalysis.ts:L67`, `refetchAdvice` `:L116`; `useFoodEstimate.estimate` `useFoodEstimate.ts:L16` | `POST /food/analyze`, `POST /food/advise`, `POST /food/estimate` | `api_v1.php:L53, L66, L70` (đều `throttle:food-analyze`) | `FoodController@analyze` `:L37`, `@advise` `:L140`, `@estimate` `:L209` | `FoodAnalysisService::getStructuredData/streamAdvice/estimateNutrition` + `DishCatalogService::groundOne` | `MealLog → meal_logs`, `Dish → dishes` |
| 11 | Nhận diện nhiều món | `/meal-picker` | `pages/MealPicker.vue:L1` (composables `L20-L24`, detect `L49`) | `useFoodDetect.detect` `useFoodDetect.ts` (⚠ chưa xác định dòng), `useMealAdvice.fetchAdvice`, `useFoodEstimate.estimate` | `POST /food/detect`, `POST /food/advise-meal`, `POST /food/estimate` | `api_v1.php:L56, L62, L70` | `FoodController@detect` `:L261`, `@adviseMeal` `:L332`, `@estimate` `:L209` | `FoodAnalysisService::detectDishes` + `DishCatalogService::ground` + `FoodSampleService::capture` | `Dish → dishes`, `FoodDetectionSample → food_detection_samples`, `MealLog → meal_logs` |
| 12 | Xác thực email | `/profile/verify-email` | `pages/profile/VerifyEmail.vue:L1` (verify `L40`, resend `L52`) | `useAuth.verifyEmail` `:L74`, `resendVerificationCode` `:L82` | `POST /auth/email/verify`, `POST /auth/email/resend` | `api_v1.php:L47, L48` | `AuthController@verifyEmail` `:L284`, `@resendVerificationCode` `:L314` | `EmailVerificationService::verify/sendCode` | `User → users`, `EmailVerificationCode → email_verification_codes` |

### 1.4 Kế hoạch dinh dưỡng

| # | Chức năng | FE route | Vue page | Composable / call | API | BE route | Controller@method | Service | Model → Table |
|---|---|---|---|---|---|---|---|---|---|
| 13 | Kế hoạch AI (daily/weekly/monthly) | `/plan` | `pages/MealPlan.vue:L1` (apply `L14`, fetchPlan `L43`) | `useMealPlan.{fetchPlan, generate, apply}` `composables/useMealPlan.ts` (⚠ dòng chính xác) | `GET /plan`, `POST /plan/generate` (`throttle:plan-generate`), `POST /plan/apply` | `api_v1.php:L100, L104, L106` | `PlanController@show` `:L21`, `@generate` `:L59`, `@apply` `:L120` | `MealPlanService::buildContext/getStructuredPlan/streamReasoning` + `PreferenceService::promptBlock` + `NutritionStandard::promptStandardsBlock` | `MealPlan → meal_plans` |
| 14 | Tiến độ kế hoạch | `/plan/progress` | `pages/PlanProgress.vue:L1` | `usePlanProgress` `composables/usePlanProgress.ts` (⚠ dòng chính xác) | `GET /plan/progress` | `api_v1.php:L103` | `PlanProgressController@index` `:L20` | `PlanProgressService` | `MealPlan → meal_plans`, `MealLog → meal_logs` |

### 1.5 Chat AI

| # | Chức năng | FE route | Vue page | Composable / call | API | BE route | Controller@method | Service | Model → Table |
|---|---|---|---|---|---|---|---|---|---|
| 15 | Chat streaming | `/chat` | `pages/Chat.vue:L1` (send `L196`, destructure `L22`) | `useChat.send` `composables/useChat.ts` (⚠ dòng chính xác) | `POST /chat` (`throttle:chat`), `POST /chat/apply-plan` | `api_v1.php:L125, L128` | `ChatController@send` `:L25`, `@applyPlan` `:L185` | `ChatService::buildUserContext/stream` + `PreferenceService` | `ChatConversation → chat_conversations`, `ChatMessage → chat_messages`, `ChatPromptLog → chat_prompt_logs` |
| 16 | Lịch sử chat | `/chat/history`, `/chat/history/:id` | `pages/ChatHistory.vue:L1`, `pages/ChatHistoryDetail.vue:L1` | `useChatHistory` (⚠ dòng chính xác) | `GET /chat/conversations`, `GET /chat/conversations/{id}`, `DELETE /chat/conversations/{id}` | `api_v1.php:L132, L133, L134` | `ChatHistoryController@index` `:L17`, `@show` `:L47`, `@destroy` `:L63` | — | `ChatConversation → chat_conversations` |

### 1.6 Body tracking

| # | Chức năng | FE route | Vue page | Composable / call | API | BE route | Controller@method | Service | Model → Table |
|---|---|---|---|---|---|---|---|---|---|
| 17 | Cân nặng | `/weight` | `pages/Weight.vue:L1` | `useWeight` `composables/useWeight.ts:L46` (function `useWeight()`) | `POST /weight/log`, `GET /weight/history`, `POST /weight/apply-goal`, `DELETE /weight/log/{id}` | `api_v1.php:L167, L168, L170, L169` | `WeightController@log` `:L14`, `@history` `:L41`, `@applyGoal` `:L59`, `@destroy` `:L48` | `WeightService::logWeight` `app/Services/WeightService.php:L20`, `::history` `:L60`, `::suggestGoal` `:L132` | `WeightLog → weight_logs`, cập nhật `users.calorie_goal` khi applyGoal |
| 18 | Nước uống | `/home` (block Nước) | `pages/Home.vue` (`useWater` ở `L28`) | `useWater` `composables/useWater.ts:L22` | `GET /water/today`, `POST /water/log`, `DELETE /water/log/{id}` | `api_v1.php:L161, L162, L163` | `WaterController@today` `:L12`, `@log` `:L31`, `@delete` `:L55` | — (logic trong controller) | `WaterLog → water_logs` |
| 19 | Streak | `/home` (block Streak) | `pages/Home.vue` (`useStreak` ở `L26`) | `useStreak` `composables/useStreak.ts:L38` | `GET /streak` (show), `POST /streak/freeze` | `api_v1.php:L149, L150` | `StreakController@show` `:L14`, `@useFreeze` `:L21` | `StreakService::recordMealActivity` `app/Services/StreakService.php:L26` (gọi tự động từ `FoodController::log`), `::useFreeze` `:L81`, `::getStreakData` `:L106` | `UserStreak → user_streaks`, `StreakMilestone → streak_milestones` |

### 1.7 Profile & Preferences

| # | Chức năng | FE route | Vue page | Composable / call | API | BE route | Controller@method | Service | Model → Table |
|---|---|---|---|---|---|---|---|---|---|
| 20 | Trang cá nhân | `/profile` | `pages/Profile.vue:L1` | `useProfile` `composables/useProfile.ts:L6` | `GET /user/profile` | `api_v1.php:L190` | `UserController@profile` `:L13` | — | `User → users` |
| 21 | Chỉnh sửa hồ sơ | `/profile/edit` | `pages/profile/Edit.vue:L1` | `useProfile.saveProfile` `useProfile.ts` (⚠ dòng chính xác) | `PATCH /user/profile` | `api_v1.php:L191` | `UserController@updateProfile` `:L20` | `WeightService::logWeight` (nếu user đổi cân nặng) | `User → users`, có thể ghi `WeightLog → weight_logs` |
| 22 | Đổi mật khẩu | `/profile/change-password` | `pages/profile/ChangePassword.vue:L1` | — (gọi `apiFetch` trực tiếp) | `POST /user/change-password` | `api_v1.php:L194` | `UserController@changePassword` `:L72` | — | `User → users` |
| 23 | Sở thích / dị ứng | `/profile/preferences` | `pages/profile/Preferences.vue:L1` | `usePreferences` `composables/usePreferences.ts:L11` | `GET /preferences`, `POST /preferences` (`throttle:20,1`), `DELETE /preferences/{id}` | `api_v1.php:L155, L156, L157` | `PreferenceController@index` `:L13`, `@store` `:L23`, `@destroy` `:L45` | `PreferenceService::listFor` `:L36`, `::add` `:L50`, `::remove` `:L91` | `UserPreference → user_preferences` |

### 1.8 Cài đặt

| # | Chức năng | FE route | Vue page | Composable / call | API | BE route | Controller@method | Service | Model → Table |
|---|---|---|---|---|---|---|---|---|---|
| 24 | Cài đặt thông báo | `/settings/notifications` | `pages/settings/Notifications.vue:L1` | `useNotifications` `composables/useNotifications.ts:L25` | `GET /notifications/settings`, `PUT /notifications/settings`, `POST /notifications/subscribe`, `POST /notifications/test`, `GET /notifications/history` | `api_v1.php:L140, L141, L138, L145, L142` | `NotificationController@getSettings` `:L45`, `@updateSettings` `:L70`, `@subscribe` `:L13`, `@sendTest` `:L130`, `@history` `:L99` | — (dùng `FcmService` từ controller) | `NotificationSubscription → notification_subscriptions`, `NotificationLog → notification_logs` |
| 25 | Hiển thị (dark mode / font) | `/settings/display` | `pages/settings/Display.vue:L1` | `useTheme`, `useUiSettings` (localStorage) | — | — | — | — | — (client-side only) |

### 1.9 Tích hợp bên ngoài

| # | Chức năng | FE route | Vue page | Composable / call | API | BE route | Controller@method | Service | Model → Table |
|---|---|---|---|---|---|---|---|---|---|
| 26 | Kết nối Strava | `/integrations/callback` | `pages/integrations/Callback.vue:L1` | `useHealthIntegration.connect` `composables/useHealthIntegration.ts:L13` (⚠ dòng method `connect`) | `GET /integrations/{provider}/connect` → redirect Strava → `GET /integrations/{provider}/callback` | `api_v1.php:L185, L175` (callback public throttle) | `IntegrationController@connect` `:L28`, `@callback` `:L47`, `@disconnect` `:L98` | `HealthProviderFactory` + `StravaProvider` (`app/Services/Health/`) | `HealthConnection → health_connections` |
| 27 | Hoạt động thể thao | `/activities` | `pages/Activities.vue:L1` | `useHealthIntegration.fetchActivities` | `GET /integrations/activities`, `POST /integrations/activities/manual` (`throttle:30,1`) | `api_v1.php:L181, L182` | `IntegrationController@activities` `:L147`, `@storeManual` `:L170` | `HealthActivityWriter` khi ghi manual + `StreakService::recordActivity` `:L36` | `HealthActivity → health_activities` |

### 1.10 Admin

| # | Chức năng | FE route | Vue page | Composable / call | API | BE route | Controller@method | Service | Model → Table |
|---|---|---|---|---|---|---|---|---|---|
| 28 | Admin Users | `/admin/users`, `/admin/users/:id` | `pages/admin/Users.vue`, `pages/admin/UserDetail.vue` | `useAdmin` `composables/useAdmin.ts:L20` (methods rải rác) | `GET /admin/users`, `GET /admin/users/{user}`, `PATCH /admin/users/{user}`, `POST /admin/users/{user}/suspend|restore|reset-password`, `DELETE /admin/users/{user}` | `api_v1.php:L201-L208` | `Admin/UserController@index` `:L18`, `@show` `:L59`, `@update` `:L64`, `@suspend` `:L94`, `@restore` `:L112`, `@resetPassword` `:L121`, `@destroy` `:L133` | — (log qua `AuditLogger`) | `User → users`, `AdminAuditLog → admin_audit_logs` |
| 29 | Admin thư viện món chuẩn | `/admin/dishes` | `pages/admin/Dishes.vue:L1` | `useAdmin.fetchDishes` (⚠ dòng chính xác) | `GET/POST /admin/dishes`, `PUT /admin/dishes/{dish}`, `DELETE /admin/dishes/{dish}` | `api_v1.php:L231-L234` | `Admin/DishController@index` `:L17`, `@store` `:L47`, `@update` `:L58`, `@destroy` `:L69` | `DishCatalogService::normalize` khi lưu | `Dish → dishes` |
| 30 | Admin Settings runtime (feature flag, rate limit, giờ nhắc) | `/admin/settings` | `pages/admin/Settings.vue:L1` | `useAdmin.fetchSettings/saveSettings` | `GET /admin/settings`, `PUT /admin/settings`, `POST /admin/settings/test/{service}` | `api_v1.php:L210, L211, L212` | `Admin/SettingsController@index` `:L17`, `@update` `:L22`, `@test` `:L90` | `SettingsService::all/setMany/get` | `Setting → settings` (key-value) |
| 31 | Admin Notifications campaigns | `/admin/notifications` | `pages/admin/Notifications.vue:L1` | `useAdmin.previewNotification/sendNotification/fetchCampaigns` | `GET /admin/notifications`, `POST /admin/notifications/preview`, `POST /admin/notifications` | `api_v1.php:L242, L243, L244` | `Admin/NotificationController@index` `:L81`, `@preview` `:L30`, `@send` `:L44` | — | `NotificationCampaign → notification_campaigns` |
| 32 | Admin AuditLogs / ChatLogs / Dataset | `/admin/audit-logs`, `/admin/chat-logs`, `/admin/dataset` | `AuditLogs.vue`, `ChatLogs.vue`, `Dataset.vue` | `useAdmin.fetch{AuditLogs,ChatLogs,DatasetStats,Dataset,DatasetSample}/deleteDatasetSample` | `GET /admin/audit-logs`; `GET /admin/chat-logs`, `GET /admin/chat-logs/{chatLog}`; `GET /admin/dataset[/stats\|/{sample}]`, `DELETE /admin/dataset/{sample}` | `api_v1.php:L214`, `L217-L218`, `L237-L240` | `Admin/AuditLogController@index` `:L12`; `Admin/ChatLogController@index` `:L16`, `@show` `:L53`; `Admin/DatasetController@stats` `:L20`, `@index` `:L30`, `@show` `:L70`, `@destroy` `:L87` | — | `AdminAuditLog → admin_audit_logs`, `ChatPromptLog → chat_prompt_logs`, `FoodDetectionSample → food_detection_samples` |

---

## 2. Công thức sửa nhanh

### 2.1 Đổi text/label hiển thị

- **UI text (button, label, heading)**: nằm trong `resources/js/pages/**/*.vue` template. Grep chuỗi tiếng Việt gốc.
  - Ví dụ: nút "Đăng nhập" → `pages/auth/Login.vue` `L149-L159`.
- **Response message BE**: `app/Http/Controllers/Api/V1/*.php`, pattern `['detail' => '…']` (lỗi) hoặc `['message' => '…']` (thành công).
  - Ví dụ: "Email hoặc mật khẩu không đúng" → `AuthController.php:L80`.
- **Prompt AI**: heredoc `PROMPT` trong `app/Services/FoodAnalysisService.php`, `MealPlanService.php`, `ChatService.php`. Nội dung system instruction lồng trong `'parts' => [['text' => '…']]`.

### 2.2 Thêm/bớt field trên form

**Thứ tự bắt buộc** (thiếu bước → vỡ):
1. **Migration** mới: `database/migrations/2026_MM_DD_add_<field>_to_<table>.php`.
2. **Model** `#[Fillable(...)]`: `app/Models/User.php:L17-L24` (hoặc model tương ứng).
3. **Controller validate**: `$request->validate([...])` — Auth register ở `AuthController.php:L27-L37`; user update ở `UserController.php:L20+`.
4. **Vue template**: thêm `ref('')` trong `<script setup>` + `<input v-model="…">` trong template.
5. Chạy `php artisan migrate`.

### 2.3 Đổi rule validation (FE + BE)

- **BE**: `app/Http/Controllers/Api/V1/<Ctrl>.php` — tìm `$request->validate([...])`.
  - Register: `AuthController.php:L27-L37` (email, password `min:8`, birth_year `between:1900,2015`, height `50-300`, weight `20-500`, calorie_goal `1000-5000`, activity_level `in:sedentary,light,moderate,active,very_active`).
- **FE** (validate trước gửi): trong Vue page, tìm hàm `validate()` hoặc regex.
  - Login: `Login.vue:L32-L43` (`emailRe`, `password.length < 6`).
  - Register: các bước tự validate trong `Register.vue`.
- ⚠ **Bắt buộc đồng bộ FE + BE** (§3.1).

### 2.4 Đổi điều kiện lọc / sắp xếp dữ liệu

- **Timeline lịch sử**: `FoodController.php:@timeline :L601` — tham số `from/to` từ query.
- **History pagination / order**: same controller. Sort thường là `orderBy('logged_at', 'desc')`.
- **Admin user list filter**: `Admin/UserController@index :L18` — query params `search`, `status`, `role`.
- **Dish catalog search**: `Admin/DishController@index :L17` — dùng `DishCatalogService::normalize` để so khớp không dấu.
- **Chat history**: `ChatHistoryController@index :L17`.

### 2.5 Đổi ngưỡng / công thức tính

| Hằng số / công thức | File | Dòng |
|---|---|---|
| BMR (Mifflin-St Jeor) | `app/Support/NutritionStandard.php` | `L64-L67` (public `bmr()`) |
| PAL (5 mức) | `NutritionStandard.php` | `L21-L27` (`const PAL`) |
| Tỉ lệ macro P/C/F | `NutritionStandard.php` | `L53-L57` (`MACRO_RATIO`) |
| Điều chỉnh mục tiêu (giảm/duy trì/tăng) | `NutritionStandard.php` | `L39-L43` (`GOAL_ADJUSTMENT`) |
| Sàn calo tối thiểu | `NutritionStandard.php` | `L46-L50` (`MIN_CALORIES`) |
| Nước 35 ml/kg | `NutritionStandard.php` | `L60` (`WATER_ML_PER_KG`) |
| Ngưỡng khớp thư viện món (fuzzy) | `app/Services/DishCatalogService.php` | `L18` (`FUZZY_THRESHOLD = 88.0`) |
| Sanity check macro/kcal (Atwater) | `app/Support/NutritionValidator.php` | `L11-L26` (tolerance `max(50, cal * 0.20)`) |
| Rate limit AI | `app/Providers/AppServiceProvider.php` | `L62-L64` (`food-analyze` 10/min, `chat` 15/min, `plan-generate` 5/min) — default; runtime ghi đè qua `Admin/Settings` |
| Streak recordMealActivity | `app/Services/StreakService.php` | `L26` |
| Weight suggestGoal (auto-adjust) | `app/Services/WeightService.php` | `L132` |
| Bộ món chuẩn (seed calo/macro) | `database/seeders/DishCatalogSeeder.php` | array `$rows` L33+; chạy `php artisan db:seed --class=DishCatalogSeeder --force` sau khi sửa |

### 2.6 Đổi thông báo lỗi

- **BE (JSON response)**: pattern `response()->json(['detail' => '…'], <code>)` cho lỗi.
  - `AuthController.php`: L24, L40, L80, L113, L119, L305.
- **FE (toast)**: `useToast()` (auto-imported). Grep `toast.error(` / `toast.success(` / `toastError(`.
- **FE (inline form)**: biến local `formError`, `errors.<field>` — Grep trong `.vue`.
- **SSE stream error**: `echo "data: " . json_encode(['type' => 'error', 'message' => '…']) . "\n\n";` — trong `FoodController::analyze:L100`, `PlanController::generate`, `ChatController::send`.

### 2.7 Đổi màu / bố cục UI

- **Tailwind classes**: sửa trực tiếp trong template `.vue` — `bg-calor-green`, `h-[50px]`, `text-[15px]`, `rounded-[14px]`.
- **Màu chủ đạo (biến CSS)**: `resources/js/assets/css/main.css`:
  - Light theme `L61-L67`: `--color-calor-green: #7c9a70`, `--color-calor-dark: #5e7a54`, `--color-calor-deep: #2c3a2b`, `--color-calor-light: #eef5e9`, `--color-calor-mint: #c3d3b3`, `--color-calor-navy: #3d5a3a`, `--color-calor-bg: #eef2e6`.
  - Dark theme `L101-L107`.
- **Font**: `resources/css/app.css:L7-L9` (`--font-sans: 'Be Vietnam Pro'…`, `--font-display: 'Quicksand'`).
- **Layout khung app**: `resources/js/layouts/AppLayout.vue` (bottom nav + header).

---

## 3. Điểm rủi ro (sửa 1 nơi vỡ nơi khác)

### 3.1 Validation trùng FE/BE
- FE (Vue) validate trước khi gửi để UX mượt; BE validate lại để bảo mật.
- Chỉ sửa 1 phía → thầy nhập từ BE (Postman) sẽ lộ hoặc ngược lại FE chặn không cho gõ nhưng BE vẫn nhận số cũ.
- **Danh sách cặp cần đồng bộ**:
  - Register: `AuthController.php:L27-L37` ↔ `Register.vue` các step validate.
  - Login: `AuthController.php:L74-L77` ↔ `Login.vue:L32-L43`.
  - Food analyze image size: `FoodController.php:L41-L46` ↔ Scan.vue giới hạn base64.

### 3.2 Cache Laravel (config, route, view)
- **Chỉ có ở production build** (`php artisan config:cache`, `route:cache`, `view:cache`). Local dev không cache.
- Nếu sửa `.env` hoặc config → prod cần chạy lại: `php artisan config:clear && php artisan cache:clear`.
- Runtime `Settings` (rate limit, feature flag) lưu trong bảng `settings` — có cache ở `SettingsService` in-request; không bị dính config cache.

### 3.3 Service worker PWA
- `vite-plugin-pwa` build ra service worker từ `resources/js/sw.ts`.
- Sau khi thay đổi FE, user cũ có thể vẫn dùng bản cũ **do SW cached**.
- Fix nhanh khi bảo vệ: `Ctrl+Shift+R` (hard refresh) hoặc DevTools → Application → Service Workers → Unregister.
- Trên dev: SW không build (chỉ inject khi `npm run prod`).

### 3.4 Migration + Seeder
- **Sửa DB schema**: bắt buộc migration mới, KHÔNG sửa file migration cũ đã chạy (sẽ conflict với DB đang chạy).
- **Seeder `DishCatalogSeeder`**: idempotent — key theo `name_normalized`. Sửa calo/macro của "Phở bò" → chạy lại `php artisan db:seed --class=DishCatalogSeeder --force` sẽ update (không tạo trùng). NHƯNG nếu đổi tên món "Phở bò" → "Phở bò tái" thì tạo record mới, record cũ vẫn còn — cần xoá tay.
- Xem `database/seeders/DishCatalogSeeder.php:L18-L27`.

### 3.5 Auto-imports Vue
- `unplugin-auto-import` cấu hình trong `vite.config.js` — mọi thứ trong `composables/`, `utils/`, `stores/` tự import.
- File TS declaration: `resources/js/auto-imports.d.ts` (generated).
- Rename composable `useFoo` → `useBar`: Vite tự regen `.d.ts` khi restart dev server. Nếu thấy TS đỏ, dừng `composer run dev` rồi chạy lại.

### 3.6 Meal plan `data_hash` (đổi hồ sơ → plan cũ thành stale)
- `MealPlanService::buildContext` tính `data_hash = sha1(...)` từ profile + preferences hash (`app/Services/MealPlanService.php` ~L140-L145).
- Đổi cân nặng / activity_level / preferences → hash thay đổi → plan đã lưu trở thành `is_stale = true` → FE hiển thị "Kế hoạch cũ, tạo lại".
- Không phải bug — feature. Nhưng nếu thầy sửa profile trong lúc demo → plan cũ đột nhiên biến thành nháp.

### 3.7 Runtime settings vs env vs code
Ba nguồn cấu hình song song:
- **Env `.env`**: khoá tĩnh (GEMINI_API_KEY, FRONTEND_URL). Đổi phải restart PHP.
- **Runtime `Settings` (DB)**: rate limit, giờ nhắc, feature flag. Đổi qua `/admin/settings`, có hiệu lực ngay.
- **Code (hằng số)**: `NutritionStandard`, `FUZZY_THRESHOLD`. Đổi trong code + save → dev reload PHP tự.
- Thứ tự ưu tiên: Settings DB > env > code default.

Ví dụ: `SettingsService::get('ai.model', config('services.gemini.model', 'gemini-2.0-flash'))` (`FoodAnalysisService.php:L18`) — Settings DB trước, env fallback, sau đó string cứng.

---

## 4. Lệnh chạy & kiểm chứng

### 4.1 Khởi động BE + FE cùng lúc
```bash
composer run dev
```
Chạy 4 process song song: `php artisan serve` (`:8000`), `queue:listen`, `pail` (tail log), `npm run dev` (Vite HMR).
Truy cập: `http://localhost:8000`.

Rời cho từng process (nếu cần debug 1 cái):
```bash
php artisan serve
npm run dev
php artisan queue:listen
```

### 4.2 Build lại PWA
```bash
npm run prod        # typecheck + vite build production, tạo /public/build/*
```
Sau đó truy cập trực tiếp `http://localhost:8000` (Laravel serve file build). Service worker `sw.ts` chỉ build khi lệnh này.

### 4.3 Xoá cache khi sửa không thấy hiệu lực

| Triệu chứng | Lệnh |
|---|---|
| Sửa `.env` không thấy đổi | `php artisan config:clear` |
| Sửa route mà 404 | `php artisan route:clear` |
| Sửa view mà FE cũ | `php artisan view:clear` |
| Xoá tất cả cache | `php artisan optimize:clear` |
| PWA cache (browser) | DevTools → Application → Service Workers → Unregister → hard refresh `Ctrl+Shift+R` |
| Vite HMR chết | Dừng `composer run dev` → chạy lại |
| Seeder không cập nhật | `php artisan db:seed --class=<TênSeeder> --force` |

### 4.4 Rollback bằng git

**Trước bảo vệ (đã làm)**:
```bash
git tag defense-2026-08-25       # đã tạo, kiểm bằng: git tag | grep defense
cp database/database.sqlite database/database.sqlite.backup-defense
```

**Trong bảo vệ — revert từng file khi sửa hỏng**:
```bash
git status                         # xem file đã sửa
git restore <file>                 # revert 1 file
git restore .                      # revert toàn bộ working tree
```

**Rollback toàn bộ về trước bảo vệ**:
```bash
git reset --hard defense-2026-08-25
cp database/database.sqlite.backup-defense database/database.sqlite
```

**Kiểm chứng nhanh sau sửa**:
```bash
php artisan test --filter=<Tên>    # test unit/feature
php artisan test                    # full suite
npm run type-check                  # vue-tsc
curl -s http://localhost:8000/api/v1/config | python -m json.tool   # verify BE alive
```

---

## 5. Tags `DEFENSE:` — Search text để nhảy trúng dòng

Mã nguồn đã cắm sẵn **~126 comment** dạng `// DEFENSE: <từ khoá>` (hoặc `/* DEFENSE: */` trong CSS) ngay trên dòng cần sửa. Phân bố:

| Layer | Số anchor | File tiêu biểu |
|---|---|---|
| Controllers (endpoint + validate + error text) | 87 | `AuthController`, `FoodController`, `UserController`, `ChatController`, `PlanController`, `WeightController`, `PreferenceController`, `WaterController`, `StreakController`, `NotificationController`, `NutritionController`, `Admin/SettingsController`, `Admin/DishController`, `AppConfigController` |
| Services (business logic, prompts) | 15 | `FoodAnalysisService` (5 prompt), `MealPlanService` (2), `ChatService` (1), `WeightService` (2), `StreakService` (2), `DishCatalogService` (1) |
| Support (hằng số công thức) | 9 | `NutritionStandard` (6), `NutritionValidator` (3) |
| Provider | 1 | `AppServiceProvider` (rate limit) |
| Seeder | 5 | `DishCatalogSeeder` (bộ món chuẩn) |
| Frontend | 9 | `Login.vue`, `Register.vue`, `main.css` |

Gõ trong editor:

```
DEFENSE:<từ khoá>
```

sẽ nhảy trúng chỗ cần đổi, không cần biết file.

### 5.1 Backend — hằng số & công thức

| Từ khoá search | File | Ý nghĩa |
|---|---|---|
| `DEFENSE:hệ số PAL` | `app/Support/NutritionStandard.php` | 5 hệ số nhân TDEE theo mức vận động |
| `DEFENSE:công thức BMR` | `NutritionStandard.php` | Mifflin-St Jeor: hệ số 10/6.25/5 và +5 nam / −161 nữ |
| `DEFENSE:điều chỉnh calo mục tiêu` | `NutritionStandard.php` | −500 / 0 / +300 kcal cho lose/maintain/gain |
| `DEFENSE:sàn calo tối thiểu` | `NutritionStandard.php` | Nam 1500, nữ 1200 |
| `DEFENSE:tỉ lệ macro` | `NutritionStandard.php` | protein/carbs/fat % (tổng = 1.0) |
| `DEFENSE:nước ml per kg` | `NutritionStandard.php` | Hệ số 35 ml/kg cân nặng |
| `DEFENSE:hệ số Atwater` | `app/Support/NutritionValidator.php` | 4/4/9 để check calo vs macro |
| `DEFENSE:ngưỡng cảnh báo macro lệch` | `NutritionValidator.php` | max(50 kcal, 20% calo) |
| `DEFENSE:text cảnh báo macro lệch` | `NutritionValidator.php` | Text banner cam ở Result.vue |
| `DEFENSE:ngưỡng fuzzy match` | `app/Services/DishCatalogService.php` | 88.0 — % khớp thư viện món |
| `DEFENSE:rate limit AI` | `app/Providers/AppServiceProvider.php` | 10/15/5 req/phút (food/chat/plan) |
| `DEFENSE:công thức gợi ý calo mới` | `app/Services/WeightService.php` | Auto-adjust goal khi cân đổi >2kg |
| `DEFENSE:khoảng calo hợp lệ WeightService` | `WeightService.php` | Chặn 1200-4000 kcal |
| `DEFENSE:mốc streak` | `app/Services/StreakService.php` | [3, 7, 14, 30, 60, 100] ngày |
| `DEFENSE:freeze token` | `StreakService.php` | Tần suất tặng token bảo vệ streak |

### 5.2 Backend — validation & thông báo

| Từ khoá search | File | Ý nghĩa |
|---|---|---|
| `DEFENSE:mật khẩu tối thiểu đăng ký` | `AuthController.php` | `min:8` password khi register |
| `DEFENSE:độ dài tên đăng ký` | `AuthController.php` | `min:2\|max:100` name |
| `DEFENSE:tuổi đăng ký` | `AuthController.php` | `birth_year between:1900,2015` |
| `DEFENSE:giới tính đăng ký` | `AuthController.php` | `gender in:male,female,other` |
| `DEFENSE:chiều cao min/max` | `AuthController.php` | `between:50,300` cm |
| `DEFENSE:cân nặng min/max` | `AuthController.php` | `between:20,500` kg |
| `DEFENSE:khoảng calo mục tiêu` | `AuthController.php` | `between:1000,5000` kcal/ngày |
| `DEFENSE:text lỗi đăng nhập sai` | `AuthController.php` | "Email hoặc mật khẩu không đúng" |
| `DEFENSE:text lỗi email trùng` | `AuthController.php` | "Email này đã được đăng ký" |
| `DEFENSE:feature flag mặc định` | `AppConfigController.php` | Default registration_open, guest_mode... |
| `DEFENSE:feature flag OAuth` | `AppConfigController.php` | google_enabled / facebook_enabled |
| `DEFENSE:feature flag AI` | `AppConfigController.php` | food_analysis_enabled / chat_enabled |

### 5.3 Backend — prompt AI

| Từ khoá search | File | Ý nghĩa |
|---|---|---|
| `DEFENSE:prompt phân tích 1 món` | `FoodAnalysisService.php` | User prompt heredoc cho `/food/analyze` |
| `DEFENSE:system instruction phân tích món` | `FoodAnalysisService.php` | Vai trò AI khi phân tích 1 món |
| `DEFENSE:system instruction estimate` | `FoodAnalysisService.php` | Vai trò khi user sửa tên → tính lại calo |
| `DEFENSE:system instruction detect nhiều món` | `FoodAnalysisService.php` | Vai trò khi phân tích nhiều món |
| `DEFENSE:system instruction lời khuyên món` | `FoodAnalysisService.php` | Vai trò khi stream lời khuyên |
| `DEFENSE:max token phân tích món` | `FoodAnalysisService.php` | maxOutputTokens = 1024 |
| `DEFENSE:system instruction kế hoạch` | `MealPlanService.php` | Vai trò khi sinh meal plan JSON |
| `DEFENSE:system instruction lý do kế hoạch` | `MealPlanService.php` | Vai trò khi stream "vì sao plan này" |
| `DEFENSE:prompt chat AI` | `ChatService.php` | Heredoc `SYS` dài — toàn bộ quy tắc chat |

### 5.4 Backend — seeder món chuẩn

| Từ khoá search | File | Ý nghĩa |
|---|---|---|
| `DEFENSE:bộ món chuẩn` | `database/seeders/DishCatalogSeeder.php` | Cảnh báo chung + lệnh chạy sau khi sửa |
| `DEFENSE:calo phở bò` | `DishCatalogSeeder.php` | 450 kcal / 1 tô |
| `DEFENSE:calo phở gà` | `DishCatalogSeeder.php` | 400 kcal / 1 tô |
| `DEFENSE:calo cơm trắng` | `DishCatalogSeeder.php` | 200 kcal / 1 chén |
| `DEFENSE:calo bánh mì` | `DishCatalogSeeder.php` | 400 kcal / 1 ổ |

> Món khác chưa gắn tag riêng — mở seeder, `Ctrl+F` tên món tiếng Việt (VD "Bún chả") là ra dòng cần sửa. Sau khi sửa: `php artisan db:seed --class=DishCatalogSeeder --force`.

### 5.5 Frontend — Vue + CSS

| Từ khoá search | File | Ý nghĩa |
|---|---|---|
| `DEFENSE:mật khẩu tối thiểu FE` | `pages/auth/Login.vue` | `password.length < 6` — nhớ đồng bộ BE (min:8) |
| `DEFENSE:text lỗi email trống` | `Login.vue` | "Vui lòng nhập email" |
| `DEFENSE:text lỗi email format` | `Login.vue` | "Email không hợp lệ" |
| `DEFENSE:text lỗi password trống` | `Login.vue` | "Vui lòng nhập mật khẩu" |
| `DEFENSE:preset mục tiêu đăng ký` | `pages/auth/Register.vue` | 3 preset lose/maintain/gain — label/icon/desc |
| `DEFENSE:giờ nhắc mặc định` | `Register.vue` | `morningTime` `07:00`, `eveningTime` `21:00` |
| `DEFENSE:tiêu đề các bước đăng ký` | `Register.vue` | 4 step wizard titles |
| `DEFENSE:lựa chọn giới tính` | `Register.vue` | Mảng `genders` (Nam/Nữ/Khác) — nhớ đồng bộ BE |
| `DEFENSE:màu chủ đạo light` | `resources/js/assets/css/main.css` | 7 biến `--color-calor-*` (light theme) |

### 5.6 Controllers — pattern search theo loại

Vì 87 anchor trong Controllers khá dày, dùng pattern để lọc nhanh:

| Pattern | Ra gì | Ví dụ |
|---|---|---|
| `DEFENSE:endpoint` | Method chính của từng controller | `DEFENSE:endpoint phân tích 1 món`, `DEFENSE:endpoint chat AI`, `DEFENSE:endpoint sinh kế hoạch AI`, `DEFENSE:endpoint log cân nặng`, `DEFENSE:endpoint đổi mật khẩu` |
| `DEFENSE:validate` | Rule validation cho update/register/store | `DEFENSE:validate profile edit`, `DEFENSE:validate Admin thêm/sửa món chuẩn` |
| `DEFENSE:giới hạn` / `DEFENSE:khoảng` | Bounds/min-max cho input | `DEFENSE:giới hạn text phân tích món`, `DEFENSE:khoảng calo mục tiêu`, `DEFENSE:khoảng temperature AI` |
| `DEFENSE:text lỗi` / `DEFENSE:text` | User-facing message trả về | `DEFENSE:text lỗi đăng nhập sai`, `DEFENSE:text từ chối chat ngoài phạm vi`, `DEFENSE:text lỗi kế hoạch hết hạn` |
| `DEFENSE:gọi Gemini` / `DEFENSE:stream` | Điểm gọi API AI | `DEFENSE:gọi Gemini phân tích món`, `DEFENSE:stream lời khuyên món` |
| `DEFENSE:grounding` | Điểm áp calo/macro từ catalog | `DEFENSE:grounding calo`, `DEFENSE:grounding nhiều món` |
| `DEFENSE:feature flag` | Toggle bật/tắt tính năng | `DEFENSE:feature flag mặc định`, `DEFENSE:feature flag OAuth`, `DEFENSE:feature flag AI` |
| `DEFENSE:target` | Giá trị mặc định target | `DEFENSE:target nước mặc định` (WaterController 2000ml hardcoded) |

### 5.7 Cách dùng nhanh

```
# Xem TẤT CẢ điểm defense trong repo
grep -rn "DEFENSE:" app/ database/seeders/ resources/js/pages/ resources/js/assets/css/

# Nhảy trúng 1 chỗ cụ thể trong VS Code
Ctrl+Shift+F → gõ "DEFENSE:tuổi đăng ký" → Enter
```

**Quy ước**:
- Comment `// DEFENSE:` (hoặc `/* DEFENSE: */` cho CSS) đặt **ngay trên** dòng cần sửa — không phải cùng dòng.
- Từ khoá không dấu cũng OK: `DEFENSE:phở bò` và `DEFENSE:pho bo` cả 2 tìm được, nhưng bản dấu chính xác hơn.
- Cần thêm anchor mới cho chỗ khác? Nói tôi vị trí + từ khoá, tôi cắm thêm.

---

## Phụ lục — Ghi chú `⚠ chưa xác định`

Các dòng có `⚠ chưa xác định` là vị trí tôi không lookup được số dòng chính xác qua grep (thường do file lớn có nhiều `function` cùng tên hoặc composable dùng export block). Verify tay bằng cách mở file → tìm chuỗi `function <name>` hoặc `<method>` cần.

- `usePasskey.loginWithPasskey`: mở `composables/usePasskey.ts` grep `function loginWithPasskey`.
- `useFoodDetect.detect`, `useMealPlan.{generate,apply,fetchPlan}`, `useChat.send`, `useChatHistory.*`, `useHealthIntegration.{connect,fetchActivities}`, `useProfile.saveProfile`, `useAdmin.*`: mở composable tương ứng grep tên function.
- `WebAuthnController@loginOptions/loginVerify`: mở `WebAuthnController.php` grep `public function loginOptions`.

Chỗ nào bạn muốn tôi verify tay thêm, chỉ định feature — tôi lookup và cập nhật.
