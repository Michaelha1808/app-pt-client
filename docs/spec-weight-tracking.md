# Spec: Weight Tracking — Theo dõi cân nặng + Adaptive Goal

> **App:** CaloEye — Laravel + Vue 3 (Vite) + Tailwind CSS 4 (iOS-style PWA)
> **Cập nhật lần cuối:** 2026-07-07
> **Trạng thái tổng:** ✅ TẤT CẢ PHASE HOÀN THÀNH — verify Docker (PHP 8.4/Postgres) + Playwright UI

---

## Mục lục
1. [Tổng quan & lý do](#1-tổng-quan--lý-do)
2. [Kiến trúc](#2-kiến-trúc)
3. [Database](#3-database)
4. [API Contract (Backend)](#4-api-contract-backend)
5. [Frontend](#5-frontend)
6. [Adaptive Goal — tính lại mục tiêu calo](#6-adaptive-goal--tính-lại-mục-tiêu-calo)
7. [Nhắc cân hàng tuần (FCM)](#7-nhắc-cân-hàng-tuần-fcm)
8. [Tích hợp AI (Chat + Meal Plan)](#8-tích-hợp-ai-chat--meal-plan)
9. [Danh sách file cần tạo / sửa](#9-danh-sách-file-cần-tạo--sửa)
10. [Checklist theo phase](#10-checklist-theo-phase)

---

## 1. Tổng quan & lý do

Hiện tại `users.weight_kg` chỉ là **1 giá trị duy nhất** (cập nhật đè qua `PATCH /user/profile`). User không có cách nào nhìn thấy tiến trình cân nặng theo thời gian — trong khi "thấy kết quả" là lý do chính giữ chân user của app dinh dưỡng.

Tính năng gồm 4 phần:
1. **Weight log**: ghi lịch sử cân nặng (1 bản ghi/ngày, ghi lại trong ngày thì đè).
2. **Biểu đồ & xu hướng**: đường cân nặng 30/90/180 ngày + trung bình trượt 7 ngày.
3. **Adaptive goal**: khi cân nặng thay đổi đáng kể → tính lại BMR/TDEE, *đề xuất* calorie_goal mới (user bấm xác nhận mới đổi, không tự động).
4. **Tích hợp AI**: nạp xu hướng cân nặng vào `ChatService::buildUserContext` và `MealPlanService` để AI tư vấn theo tiến độ thực tế.

**Nguyên tắc đồng bộ:** `users.weight_kg` luôn = cân nặng của bản ghi weight_log **mới nhất**. Ngược lại, khi user sửa cân nặng ở `/profile/edit` (`PATCH /user/profile` với `weight_kg`) → tự tạo/cập nhật weight_log của hôm nay. Một nguồn nhập, hai nơi đọc.

---

## 2. Kiến trúc

```
[Vue SPA]                                  [Laravel API /api/v1]
   │                                             │
   │── POST   /weight/log ─────────────────────▶ │ upsert theo (user, ngày) → sync users.weight_kg
   │── GET    /weight/history?range=30 ─────────▶ │ → { entries[], trend, bmi, goal_suggestion? }
   │── DELETE /weight/log/{id} ─────────────────▶ │ → 204, re-sync users.weight_kg
   │── POST   /weight/apply-goal ───────────────▶ │ chấp nhận calorie_goal đề xuất
   │
   │  PATCH /user/profile { weight_kg } ────────▶ │ (đã có) → thêm side-effect: upsert weight_log hôm nay
   │
   [Scheduler] weekly ──▶ App\Console\Commands\SendWeighInReminder ──▶ FcmService
```

- Route nhóm `Route::middleware('auth:sanctum')->prefix('weight')` trong `routes/api_v1.php` — đặt cạnh nhóm `water` (mẫu tương tự: `WaterController`).
- Logic upsert + sync + tính trend đặt trong `App\Services\WeightService` (controller mỏng, theo pattern `StreakService`).

---

## 3. Database

### Migration `create_weight_logs_table`

```php
Schema::create('weight_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->decimal('weight_kg', 5, 1);
    $table->date('logged_date');                  // 1 bản ghi / user / ngày
    $table->string('note', 200)->nullable();      // "sau Tết", "bắt đầu cut"…
    $table->timestamps();

    $table->unique(['user_id', 'logged_date']);
    $table->index(['user_id', 'logged_date']);
});
```

Không thêm cột mới vào `users` — `weight_kg` sẵn có đóng vai trò "cân nặng hiện tại" (denormalized từ log mới nhất).

### Model `App\Models\WeightLog`

- `$fillable = ['user_id', 'weight_kg', 'logged_date', 'note']`
- `$casts = ['logged_date' => 'date', 'weight_kg' => 'float']`
- Quan hệ `User::weightLogs()` (hasMany, orderByDesc logged_date).

---

## 4. API Contract (Backend)

### 4.1 POST `/weight/log`
```json
// Request
{ "weight_kg": 64.5, "logged_date": "2026-07-07", "note": "sáng, chưa ăn" }
// logged_date optional → mặc định hôm nay; không cho ngày tương lai; note optional

// Response 201 (200 nếu đè bản ghi cùng ngày)
{
  "entry": { "id": 12, "weight_kg": 64.5, "logged_date": "2026-07-07", "note": "sáng, chưa ăn" },
  "current_weight_kg": 64.5,
  "goal_suggestion": {                    // null nếu chưa đủ điều kiện (xem mục 6)
    "current_goal": 2000,
    "suggested_goal": 1900,
    "reason": "Bạn đã giảm 2.1kg so với lúc đặt mục tiêu"
  }
}

// Response 422
{ "detail": "weight_kg phải trong khoảng 20–500" }
```

Validation: `weight_kg: required|numeric|between:20,500` (khớp `UserController::updateProfile`), `logged_date: sometimes|date|before_or_equal:today`, `note: nullable|string|max:200`.

### 4.2 GET `/weight/history?range=30`
`range` ∈ {30, 90, 180}, mặc định 30.

```json
// Response 200
{
  "range": 30,
  "entries": [
    { "id": 12, "weight_kg": 64.5, "logged_date": "2026-07-07", "note": null }
  ],
  "trend": {
    "start_weight_kg": 66.0,           // bản ghi đầu trong range
    "current_weight_kg": 64.5,
    "delta_kg": -1.5,
    "avg_7d_kg": 64.8,                 // trung bình trượt 7 ngày gần nhất
    "weekly_rate_kg": -0.4             // tốc độ thay đổi kg/tuần (linear qua range)
  },
  "bmi": { "value": 22.3, "label": "Bình thường" },   // null nếu thiếu height_cm
  "goal_suggestion": null
}
```

### 4.3 DELETE `/weight/log/{weightLog}`
- Chỉ chủ sở hữu (policy hoặc check `user_id`). Response 204.
- Sau khi xoá: re-sync `users.weight_kg` = bản ghi mới nhất còn lại (giữ nguyên nếu không còn bản ghi nào).

### 4.4 POST `/weight/apply-goal`
```json
// Request
{ "calorie_goal": 1900 }
// Response 200
{ "message": "Đã cập nhật mục tiêu calo", "calorie_goal": 1900 }
```
Validation giống profile: `integer|between:1000,5000`. Ghi `usage_events` type `weight_goal_apply` để theo dõi mức dùng.

### 4.5 Side-effect trên API sẵn có
- `PATCH /user/profile` với `weight_kg` → gọi `WeightService::logWeight($user, $weightKg, today())` (upsert). Đảm bảo không tạo vòng lặp (service sync ngược lại `users.weight_kg` bằng `updateQuietly`).

---

## 5. Frontend

### 5.1 Trang `/weight` — `resources/js/pages/Weight.vue`
Route: `{ path: '/weight', meta: { layout: 'app', middleware: 'auth-strict' } }`.

Bố cục (iOS-style, tham khảo `MealPlan.vue` / `Activities.vue`):
1. **Header card**: cân nặng hiện tại (số to) + delta so với đầu range (`▼ 1.5 kg / 30 ngày`, xanh nếu đúng hướng mục tiêu) + BMI badge.
2. **Biểu đồ đường SVG** (tự vẽ, không thêm lib — app chưa có chart lib): trục X theo ngày, đường raw + đường avg 7 ngày mờ hơn; segmented control `30 / 90 / 180 ngày`.
3. **Nút "Ghi cân nặng"** → bottom sheet: number input (bước 0.1, prefill giá trị gần nhất) + note + nút Lưu. Nếu hôm nay đã ghi → hiển thị "Ghi đè hôm nay".
4. **Card đề xuất mục tiêu** (khi `goal_suggestion != null`): "Mục tiêu calo nên là 1900 kcal (hiện tại 2000)" + nút *Áp dụng* / *Bỏ qua* (bỏ qua → lưu localStorage `goal_suggestion_dismissed_at`, không hỏi lại trong 14 ngày).
5. **Danh sách bản ghi** (mới → cũ), swipe/nút xoá.

### 5.2 Điểm chạm ở màn hình sẵn có
- **`/profile` (`Profile.vue`)**: dòng BMI/BMR hiện tại thêm mini-sparkline + chevron điều hướng sang `/weight`.
- **`/home` (`Home.vue`)**: nếu ≥7 ngày chưa ghi cân → card nhắc nhẹ "Đã 1 tuần bạn chưa cân — cập nhật để AI tư vấn sát hơn" (dismiss được).
- **`/profile/edit`**: giữ nguyên field weight (backend tự upsert log).

### 5.3 Composable `useWeight.ts`
`resources/js/composables/useWeight.ts`: `history(range)`, `log(payload)`, `remove(id)`, `applyGoal(goal)` — bọc `apiFetch` theo pattern các composable sẵn có.

---

## 6. Adaptive Goal — tính lại mục tiêu calo

Công thức **Mifflin-St Jeor** (đủ dữ liệu `birth_year`, `gender`, `height_cm`):

```
BMR nam = 10×weight + 6.25×height − 5×age + 5
BMR nữ  = 10×weight + 6.25×height − 5×age − 161
TDEE    = BMR × 1.375   (hệ số hoạt động nhẹ — mặc định, chưa hỏi activity level)
```

**Điều kiện sinh `goal_suggestion`** (tính trong `WeightService::suggestGoal`):
- User có đủ height/gender/birth_year, VÀ
- |cân hiện tại − cân tại lần cuối đổi `calorie_goal`| ≥ **2 kg** (lưu mốc bằng bản ghi weight_log gần nhất trước thời điểm `usage_events` type `weight_goal_apply` cuối; nếu chưa từng apply → so với bản ghi weight_log đầu tiên), VÀ
- `suggested_goal` (làm tròn về bội 50) lệch `current_goal` ≥ 100 kcal.

`suggested_goal = TDEE − 300` nếu xu hướng mục tiêu là giảm cân (goal hiện tại < TDEE), `TDEE + 300` nếu tăng cân, `TDEE` nếu duy trì. Clamp `[1200, 4000]`.

**Không bao giờ tự đổi** `calorie_goal` — chỉ đề xuất, user bấm Áp dụng (mục 4.4).

---

## 7. Nhắc cân hàng tuần (FCM)

- Command `App\Console\Commands\SendWeighInReminder` — chạy **thứ 2 hàng tuần 07:30** (schedule trong `routes/console.php` hoặc `bootstrap/app.php` tùy nơi project đăng ký schedule hiện tại — kiểm tra chỗ các job noti sẵn có).
- Đối tượng: user có notification subscription active VÀ không có weight_log trong 7 ngày qua.
- Nội dung: `"⚖️ Cập nhật cân nặng tuần này để theo dõi tiến độ nhé!"`, url `/weight` (tận dụng cột `url` của `notification_logs`; hoạt động đầy đủ khi spec-notification-deeplink được implement).
- Tôn trọng setting: thêm toggle `weigh_in_reminder` vào màn `/settings/notifications` (mặc định bật) — lưu cùng cơ chế settings noti hiện có.

---

## 8. Tích hợp AI (Chat + Meal Plan)

### 8.1 `ChatService::buildUserContext` — thêm khối `weightTrendBlock`
Chèn sau khối chỉ số cơ thể hiện có:

```
## Xu hướng cân nặng (30 ngày)
- Hiện tại: 64.5 kg (BMI 22.3)
- Thay đổi: −1.5 kg trong 30 ngày (≈ −0.4 kg/tuần)
- Lần cân gần nhất: 2026-07-07
```

- Không có log nào → ghi 1 dòng "User chưa ghi cân nặng định kỳ — có thể khuyến khích nhẹ nhàng."
- Cache chung cơ chế context hiện tại (nếu có), bust khi POST /weight/log.

### 8.2 `MealPlanService`
Inject cùng khối trend vào prompt generate plan (daily/monthly) — AI điều chỉnh calo kế hoạch theo tốc độ giảm/tăng thực tế thay vì chỉ theo goal tĩnh.

---

## 9. Danh sách file cần tạo / sửa

**Tạo mới**
| File | Nội dung |
|------|----------|
| `database/migrations/2026_07_07_000002_create_weight_logs_table.php` | Bảng weight_logs |
| `database/migrations/2026_07_07_000003_add_weigh_in_reminder_to_users_table.php` | Cột `weigh_in_reminder_enabled` |
| `tests/Feature/WeightControllerTest.php` | 13 feature test |
| `app/Models/WeightLog.php` | Model |
| `app/Services/WeightService.php` | upsert log, sync users.weight_kg, trend, suggestGoal |
| `app/Http/Controllers/Api/V1/WeightController.php` | log / history / destroy / applyGoal |
| `app/Console/Commands/SendWeighInReminder.php` | Push nhắc cân thứ 2 |
| `resources/js/pages/Weight.vue` | Trang biểu đồ + log |
| `resources/js/composables/useWeight.ts` | API wrapper |

**Sửa**
| File | Thay đổi |
|------|----------|
| `routes/api_v1.php` | Nhóm route `weight` |
| `app/Models/User.php` | Quan hệ `weightLogs()` |
| `app/Http/Controllers/Api/V1/UserController.php` | `updateProfile` upsert weight_log khi có `weight_kg` |
| `app/Services/ChatService.php` | `weightTrendBlock` trong `buildUserContext` |
| `app/Services/MealPlanService.php` | Inject trend vào prompt |
| `resources/js/router/index.ts` | Route `/weight` |
| `resources/js/pages/Profile.vue` | Sparkline + link sang `/weight` |
| `resources/js/pages/Home.vue` | Card nhắc cân ≥7 ngày |
| Schedule đăng ký job (`routes/console.php`…) | Đăng ký SendWeighInReminder |
| Màn `/settings/notifications` + API settings | Toggle `weigh_in_reminder` |

---

## 10. Checklist theo phase

### Phase 1 — Backend nền tảng (log + history + sync) ✅
- [x] ✅ Migration `weight_logs` (`2026_07_07_000002_create_weight_logs_table.php`) + Model + quan hệ User
- [x] ✅ `WeightService`: `logWeight` (upsert, sync `users.weight_kg` bằng `saveQuietly`), `history` (entries + trend + bmi), `deleteEntry` re-sync
- [x] ✅ `WeightController` + routes: POST /log, GET /history, DELETE /log/{id}
- [x] ✅ Side-effect `PATCH /user/profile` → upsert weight_log hôm nay (không đổi note nếu không truyền)
- [x] ✅ Feature test (`tests/Feature/WeightControllerTest.php`, 13 test): upsert cùng ngày, sync 2 chiều, ngày cũ không ghi đè hiện tại, xoá re-sync, chặn ngày tương lai, chặn xoá log người khác — chạy PASS trên Docker (PHP 8.4 + Postgres thật). Phát hiện & sửa 1 bug thật: quan hệ `weightLogs()` có sẵn `orderByDesc`, chain thêm `orderBy` không ghi đè được thứ tự → phải dùng `reorder()`.

### Phase 2 — Frontend trang `/weight` ✅
- [x] ✅ `useWeight.ts` + route `/weight`
- [x] ✅ `Weight.vue`: header card + biểu đồ SVG (raw + avg7d, 30/90/180) + bottom sheet ghi cân + danh sách/xoá
- [x] ✅ `Profile.vue` link "Theo dõi cân nặng"; `Home.vue` card nhắc ≥7 ngày (dismiss theo ngày, localStorage)
- [x] ✅ Verify luồng thật bằng Playwright (đăng nhập → /weight → ghi cân → chart/list cập nhật → Profile hiển thị "Hiện tại 73.5 kg" → Home không lỗi), có screenshot

### Phase 3 — Adaptive goal + nhắc cân ✅
- [x] ✅ `WeightService::suggestGoal` (Mifflin-St Jeor, điều kiện ≥2kg & ≥100kcal, mốc so sánh = lần apply-goal gần nhất hoặc log đầu tiên) + trả trong response log/history
- [x] ✅ POST `/weight/apply-goal` + `usage_events` type `weight_goal_apply`
- [x] ✅ Card đề xuất trong `Weight.vue` (Áp dụng / Bỏ qua 14 ngày qua localStorage)
- [x] ✅ `SendWeighInReminder` (`notify:weigh-in`) + `Schedule::command(...)->weeklyOn(1, '07:30')` trong `routes/console.php` + toggle `weigh_in_reminder` ở `/settings/notifications` (migration `weigh_in_reminder_enabled` cột users, default true) — chạy thử lệnh trên Docker không lỗi

### Phase 4 — Tích hợp AI ✅
- [x] ✅ `weightTrendBlock` trong `ChatService::buildUserContext` (dùng `WeightService::history()`)
- [x] ✅ Inject `weight_trend_line` vào cả 3 prompt của `MealPlanService` (daily/weekly/monthly) + vào `data_hash` để plan cũ stale khi cân nặng đổi nhiều
- [x] ✅ Verify DI: gọi `/api/v1/chat` với token thật → vào tới validate() (không lỗi resolve constructor), confirm `WeightService` được inject đúng vào cả `ChatService` và `MealPlanService`. Chưa test nội dung hội thoại thật với Gemini (cần API key + thời gian phản hồi AI — để verify thủ công sau khi có key)
