# Spec: Meal Plan — Tư vấn kế hoạch ăn uống & tập luyện bằng AI

> **App:** CaloEye — Vue 3 SPA + Laravel 13 + Tailwind CSS 4 (iOS-style PWA)
> **Cập nhật lần cuối:** 2026-06-25
> **Trạng thái tổng:** 🟢 Đã implement CẢ HAI: (1) tư vấn hội thoại trong Chat, (2) trang `/plan` riêng + bảng `meal_plans` (daily + monthly). Còn lại: Phase 3 (activity_logs) + scheduled job.

---

## ⚠️ Ghi chú triển khai thực tế (2026-06-25)

Theo yêu cầu, tính năng được **tích hợp vào chức năng tư vấn AI có sẵn** (`Chat.vue`) thay vì xây trang `/plan` + bảng `meal_plans` như thiết kế ban đầu bên dưới. Cách tiếp cận hội thoại:

- Người dùng hỏi trực tiếp trong chat ("Lên kế hoạch ăn cho ngày mai", "Gợi ý lịch tập tháng này") → AI trả lời kèm kế hoạch.
- **Yêu cầu "thay đổi theo dữ liệu hàng ngày" được đảm bảo:** mỗi request, backend rebuild ngữ cảnh từ DB (`buildUserContext`) — hồ sơ + BMR/TDEE + thống kê hôm nay + trung bình 7 ngày → AI luôn tư vấn dựa trên số liệu mới nhất.

**File đã tạo/sửa:**
| File | Vai trò |
|------|---------|
| `app/Services/ChatService.php` | `buildUserContext()` (BMR/TDEE/today/7-day) + `streamReply()` Gemini SSE |
| `app/Http/Controllers/Api/V1/ChatController.php` | `send()` — SSE streaming, validate messages |
| `routes/api_v1.php` | `POST /chat` (auth + throttle 15/min) |
| `resources/js/types/chat.ts` | `ChatMessage`, `ChatTurn`, `ChatStreamEvent` |
| `resources/js/composables/useChat.ts` | fetch + parse SSE stream |
| `resources/js/pages/Chat.vue` | Bỏ phản hồi giả → stream AI thật, gợi ý theo kế hoạch |

Phần thiết kế trang `/plan` + persist `meal_plans` bên dưới giữ lại làm **định hướng Phase 2** (khi cần lưu/đối chiếu kế hoạch theo ngày).

---

## Mục lục
1. [Tổng quan & mục tiêu](#1-tổng-quan--mục-tiêu)
2. [Khái niệm & phạm vi](#2-khái-niệm--phạm-vi)
3. [Nguồn dữ liệu đầu vào](#3-nguồn-dữ-liệu-đầu-vào)
4. [Kiến trúc tổng thể](#4-kiến-trúc-tổng-thể)
5. [Database — bảng mới](#5-database--bảng-mới)
6. [API Contract (Backend)](#6-api-contract-backend)
7. [Backend — Service & Controller](#7-backend--service--controller)
8. [AI Prompt design](#8-ai-prompt-design)
9. [Cơ chế cập nhật theo dữ liệu hàng ngày](#9-cơ-chế-cập-nhật-theo-dữ-liệu-hàng-ngày)
10. [Frontend — Composable & màn hình](#10-frontend--composable--màn-hình)
11. [TypeScript Types](#11-typescript-types)
12. [Danh sách file cần tạo / sửa](#12-danh-sách-file-cần-tạo--sửa)
13. [Checklist tổng](#13-checklist-tổng)
14. [Notes kỹ thuật](#14-notes-kỹ-thuật)

---

## 1. Tổng quan & mục tiêu

Dùng mô hình AI (Gemini) để **đề xuất kế hoạch ăn uống và tập luyện** cho người dùng:

- **Kế hoạch ngày mai** (`daily`): gợi ý từng bữa (sáng / trưa / tối / phụ) kèm calo + macros, lượng nước mục tiêu, và các bài tập đề xuất cho ngày hôm sau.
- **Kế hoạch tháng này** (`monthly`): định hướng tổng thể trong tháng — mục tiêu calo trung bình/ngày, phân bổ macros, lịch tập theo tuần, cột mốc cân nặng dự kiến.

Điểm cốt lõi: **kế hoạch thay đổi theo dữ liệu được thêm vào hàng ngày**. Mỗi khi người dùng log bữa ăn / nước / cập nhật cân nặng, kế hoạch kế tiếp được tạo lại dựa trên hành vi thực tế (đã ăn vượt/thiếu mục tiêu, độ tuân thủ, xu hướng macros…).

**AI Model:** Gemini `gemini-2.0-flash` (qua Guzzle REST, giống `FoodAnalysisService`).
- JSON mode để lấy structured plan (chính xác, render UI).
- Streaming để lấy phần narrative "lý do & lời khuyên" (tự nhiên, hiển thị dần).
- Khóa cấu hình tái dùng: `services.gemini.key`, `services.gemini.model`.

> **Lưu ý:** Spec food-analysis ghi "OpenAI" nhưng code thực tế dùng **Gemini**. Spec này theo đúng implementation hiện tại (Gemini + Guzzle).

---

## 2. Khái niệm & phạm vi

### Hai loại scope

| Scope | Phạm vi thời gian | Mức chi tiết | Tần suất tạo lại |
|-------|-------------------|--------------|------------------|
| `daily` | Ngày mai (1 ngày) | Từng bữa cụ thể + bài tập + nước | Mỗi ngày / khi data thay đổi đáng kể |
| `monthly` | Tháng hiện tại | Mục tiêu & định hướng theo tuần | Đầu tháng / khi cân nặng đổi |

### Phạm vi Phase 1 (MVP)
- ✅ Tính TDEE/BMR từ profile (Mifflin-St Jeor).
- ✅ `daily` plan: AI sinh kế hoạch ăn + tập cho ngày mai dựa trên lịch sử 7 ngày.
- ✅ Lưu plan vào DB (persist, không gọi AI lại mỗi lần mở app).
- ✅ Stream phần narrative "vì sao kế hoạch này".
- ❌ `monthly` plan → Phase 2.
- ❌ Bảng log tập luyện thực tế (`activity_logs`) → Phase 3 (hiện workout chỉ là **đề xuất**, chưa đối chiếu thực hiện).

---

## 3. Nguồn dữ liệu đầu vào

Tất cả đã có sẵn trong DB (không cần tạo mới cho input):

| Nguồn | Bảng / field | Dùng để |
|-------|--------------|---------|
| Profile | `users`: `birth_year`, `gender`, `height_cm`, `weight_kg`, `calorie_goal` | Tính BMR/TDEE, cá nhân hóa |
| Lịch sử ăn | `meal_logs`: `calories, protein, carbs, fat, sodium, logged_at` | Xu hướng calo/macros 7–30 ngày, độ tuân thủ |
| Nước | `water_logs` | Mục tiêu & thói quen uống nước |
| Streak | `user_streaks` | Mức độ gắn bó → điều chỉnh độ "khó" của kế hoạch |

### Chỉ số dẫn xuất (tính ở backend trước khi đưa vào prompt)

```
age           = năm hiện tại - birth_year
BMR (Mifflin) = 10*weight_kg + 6.25*height_cm - 5*age + (gender=male ? +5 : -161)
TDEE          = BMR * activity_factor   (mặc định 1.375 — nhẹ nhàng; có thể chỉnh sau)
avg_calories_7d   = trung bình calo/ngày 7 ngày gần nhất (bỏ ngày không log)
avg_macros_7d     = trung bình protein/carbs/fat
adherence_7d      = số ngày đạt ±10% calorie_goal / số ngày có log
trend             = "tăng" | "giảm" | "ổn định" (so sánh tuần này vs tuần trước)
```

---

## 4. Kiến trúc tổng thể

```
[MealPlan.vue]  (màn hình kế hoạch)
   └── onMounted → useMealPlan.fetchPlan('daily')
          │
          ▼ GET /api/v1/plan?scope=daily
   [PlanController@show]
     ├── Có plan hợp lệ trong DB (chưa stale)?  → trả luôn (không gọi AI)
     └── Không → 404 + { needs_generation: true }
          │
          ▼ (user bấm "Tạo kế hoạch" hoặc auto)
   useMealPlan.generate('daily')
          │
          ▼ POST /api/v1/plan/generate  (SSE)
   [PlanController@generate]
     └── MealPlanService
           ├── buildContext()  — tính BMR/TDEE/avg/adherence từ DB
           ├── Phase A: getStructuredPlan()  — Gemini JSON mode
           │      └── emit SSE {"type":"plan","data":{...MealPlan}}
           │      └── lưu vào bảng meal_plans
           └── Phase B: streamReasoning()    — Gemini streaming
                  └── emit SSE {"type":"text","delta":"..."} × N
                        └── emit data: [DONE]
```

**Thiết kế 2-phase** (giống food-analysis): structured data render ngay, narrative stream dần.

---

## 5. Database — bảng mới

### Migration: `create_meal_plans_table`

**File:** `database/migrations/2026_06_25_000001_create_meal_plans_table.php`

```php
Schema::create('meal_plans', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->enum('scope', ['daily', 'monthly']);
    $table->date('target_date');          // daily: ngày áp dụng (ngày mai); monthly: ngày 1 của tháng
    $table->json('plan');                 // structured MealPlan (xem §11)
    $table->text('reasoning')->nullable(); // narrative đã stream xong (lưu để xem lại offline)
    $table->json('context_snapshot');     // BMR/TDEE/avg/adherence tại thời điểm tạo
    $table->string('data_hash', 40);      // hash của input → phát hiện stale (xem §9)
    $table->timestamps();

    $table->unique(['user_id', 'scope', 'target_date']);
    $table->index(['user_id', 'scope']);
});
```

### Model: `MealPlan`

**File:** `app/Models/MealPlan.php`

```php
protected $fillable = [
    'user_id', 'scope', 'target_date', 'plan',
    'reasoning', 'context_snapshot', 'data_hash',
];

protected function casts(): array
{
    return [
        'target_date'      => 'date',
        'plan'             => 'array',
        'context_snapshot' => 'array',
    ];
}

public function user(): BelongsTo { return $this->belongsTo(User::class); }
```

Thêm vào `User`: `public function mealPlans(): HasMany { return $this->hasMany(MealPlan::class); }`

---

## 6. API Contract (Backend)

Tất cả endpoint **yêu cầu auth** (`auth:sanctum`) — kế hoạch là dữ liệu cá nhân hóa.

### 6.1 GET `/api/v1/plan?scope=daily`

Lấy kế hoạch hiện hành (nếu có, chưa stale).

```json
// 200 — có plan
{
  "plan": { ...MealPlan },
  "reasoning": "Hôm nay bạn đã ăn hơi nhiều tinh bột...",
  "target_date": "2026-06-26",
  "is_stale": false,
  "generated_at": "2026-06-25T08:00:00Z"
}

// 200 — chưa có plan / đã stale → cần tạo
{
  "plan": null,
  "needs_generation": true,
  "reason": "stale" | "missing"
}
```

**Query:** `scope` = `daily` (default) | `monthly`.

### 6.2 POST `/api/v1/plan/generate`  — SSE streaming

```json
// Request
{ "scope": "daily" }   // | "monthly"
```

**Rate limit:** `throttle:5,1` (tốn token AI, giới hạn chặt hơn analyze).

**Response headers (SSE):** giống food-analysis
```
Content-Type: text/event-stream; charset=utf-8
Cache-Control: no-cache, no-store
X-Accel-Buffering: no
```

**SSE event stream:**
```
data: {"type":"plan","data":{...MealPlan}}

data: {"type":"text","delta":"Dựa trên 7 ngày qua, "}
data: {"type":"text","delta":"bạn có xu hướng ăn thiếu protein..."}
...
data: [DONE]
```

**Event `type: "plan"` → MealPlan (daily):** xem cấu trúc đầy đủ ở §11.

**Lỗi:**
```json
// 422 — thiếu dữ liệu profile để tính BMR
{ "message": "Cần hoàn thiện hồ sơ (chiều cao, cân nặng, năm sinh) để tạo kế hoạch." }

// trong stream, sau khi headers đã gửi
data: {"type":"error","message":"Không thể tạo kế hoạch. Vui lòng thử lại."}
```

### 6.3 GET `/api/v1/plan/history?scope=daily`

Danh sách plan đã tạo (xem lại các ngày trước). Trả 14 bản gần nhất.

```json
{ "plans": [ { "target_date": "...", "plan": {...}, "generated_at": "..." } ] }
```

---

## 7. Backend — Service & Controller

### 7.1 MealPlanService

**File:** `app/Services/MealPlanService.php`

Cấu trúc giống `FoodAnalysisService` (Guzzle + Gemini).

**`buildContext(User $user, string $scope): array`**
- Tính `age`, `BMR`, `TDEE` (Mifflin-St Jeor).
- Query `meal_logs` 7 ngày (daily) hoặc 30 ngày (monthly) → `avg_calories`, `avg_macros`, `adherence`, `trend`.
- Query `water_logs` → trung bình ml/ngày.
- Trả mảng context + `data_hash` (xem §9).
- Nếu thiếu `height_cm`/`weight_kg`/`birth_year` → throw `RuntimeException` → controller trả 422.

**`getStructuredPlan(array $context, string $scope): array`**
- Gemini `generateContent`, `responseMimeType: application/json`, `maxOutputTokens: 2048`.
- System prompt: chuyên gia dinh dưỡng + HLV thể hình, ẩm thực Việt.
- Trả mảng MealPlan đúng schema (§11). Validate keys bắt buộc trước khi lưu.

**`streamReasoning(array $context, array $plan, string $scope): Generator`**
- Gemini `streamGenerateContent?alt=sse`, streaming.
- Yield từng `delta` text. Controller append để lưu vào `reasoning`.

### 7.2 PlanController

**File:** `app/Http/Controllers/Api/V1/PlanController.php`

- `show(Request)` — đọc plan hiện hành; so `data_hash` để set `is_stale`/`needs_generation`.
- `generate(Request)` — SSE:
  1. Validate `scope`.
  2. `buildContext()` (try/catch → 422 nếu thiếu profile).
  3. `return response()->stream(...)` với headers SSE; `while (ob_get_level()) ob_end_clean()`.
  4. `getStructuredPlan()` → emit `plan` → `flush()` → **upsert** vào `meal_plans` (theo unique `user_id+scope+target_date`).
  5. `streamReasoning()` → emit `text` từng chunk → tích lũy → cập nhật cột `reasoning` khi xong.
  6. Emit `[DONE]`. Wrap try/catch → emit `error`.
- `history(Request)` — 14 bản gần nhất theo scope.

### 7.3 Routes (`routes/api_v1.php`)

```php
Route::middleware('auth:sanctum')->prefix('plan')->group(function () {
    Route::get('/', [PlanController::class, 'show']);
    Route::get('/history', [PlanController::class, 'history']);
    Route::middleware('throttle:5,1')->post('/generate', [PlanController::class, 'generate']);
});
```

---

## 8. AI Prompt design

### System prompt (structured, daily)
```
Bạn là chuyên gia dinh dưỡng kiêm huấn luyện viên thể hình, am hiểu ẩm thực Việt Nam.
Nhiệm vụ: lập kế hoạch ăn uống và tập luyện cho NGÀY MAI dựa trên dữ liệu thực tế của người dùng.
CHỈ trả về JSON hợp lệ đúng schema, không giải thích thêm.
Ưu tiên món ăn Việt phổ biến, dễ mua/dễ nấu. Tổng calo các bữa phải xấp xỉ mục tiêu (±5%).
```

### User prompt (structured, daily)
```
Hồ sơ: {gender}, {age} tuổi, {height_cm}cm, {weight_kg}kg.
BMR {bmr} kcal, TDEE {tdee} kcal, mục tiêu {calorie_goal} kcal/ngày.

7 ngày qua:
- Calo trung bình: {avg_calories} kcal/ngày (xu hướng: {trend})
- Macros TB: protein {p}g, carbs {c}g, fat {f}g
- Độ tuân thủ mục tiêu: {adherence}%
- Nước TB: {avg_water} ml/ngày

Hãy lập kế hoạch cho ngày mai, trả JSON đúng schema:
{
  "summary": "1 câu tóm tắt định hướng ngày mai",
  "target_calories": <int>,
  "target_macros": { "protein": <g>, "carbs": <g>, "fat": <g> },
  "water_target_ml": <int>,
  "meals": [
    { "slot": "breakfast"|"lunch"|"dinner"|"snack",
      "name": "tên bữa/món",
      "items": ["món 1", "món 2"],
      "calories": <int>, "protein": <g>, "carbs": <g>, "fat": <g> }
  ],
  "workouts": [
    { "name": "tên bài tập", "type": "cardio"|"strength"|"flexibility",
      "duration_min": <int>, "intensity": "low"|"medium"|"high",
      "est_calories_burned": <int> }
  ],
  "tips": ["lời khuyên ngắn 1", "lời khuyên 2"]
}
```

### streamReasoning prompt
```
Dựa trên kế hoạch vừa lập và dữ liệu 7 ngày, viết 3–4 câu tiếng Việt giải thích:
1. Vì sao điều chỉnh theo hướng này (so với những gì họ đã ăn).
2. Điểm cần chú ý nhất ngày mai.
3. Một động viên ngắn gắn với streak/độ tuân thủ.
Tự nhiên, thân thiện, không markdown heading, có thể dùng emoji.
```

### Monthly (Phase 2)
- Schema khác: `weekly_focus[]` (4 tuần), `avg_daily_calories`, `expected_weight_change_kg`, `weekly_workout_split`.

---

## 9. Cơ chế cập nhật theo dữ liệu hàng ngày

Đây là yêu cầu cốt lõi: **kế hoạch thay đổi theo dữ liệu thêm vào hàng ngày.**

### data_hash — phát hiện stale
Khi `buildContext()` chạy, tính:
```
data_hash = sha1( avg_calories_7d | adherence_7d | trend | weight_kg | calorie_goal )
```
- Lưu cùng plan.
- `GET /plan`: tính lại `data_hash` từ dữ liệu hiện tại; nếu khác bản đã lưu → `is_stale = true` → gợi ý người dùng tạo lại.
- **Không tự gọi AI** khi mở app (tốn token); chỉ đánh dấu stale + nút "Cập nhật kế hoạch".

### Khi nào coi là stale
- Đã sang ngày mới và plan `daily` là của ngày cũ.
- `avg_calories_7d` lệch > 15% so với snapshot.
- `weight_kg` hoặc `calorie_goal` thay đổi.
- `adherence` đổi bậc (vd từ <70% lên >90%).

### Trigger tạo lại
- Người dùng bấm "Cập nhật kế hoạch" trên `MealPlan.vue`.
- (Phase 2 tùy chọn) Scheduled job buổi tối tự sinh plan `daily` cho ngày mai cho user có streak — tái dùng hạ tầng notification/cron đã có.

---

## 10. Frontend — Composable & màn hình

### 10.1 Composable `useMealPlan`

**File:** `resources/js/composables/useMealPlan.ts`

```typescript
plan: Ref<MealPlan | null>
reasoning: Ref<string>          // narrative (stream hoặc đã lưu)
isStale: Ref<boolean>
loading: Ref<boolean>           // đang fetch GET
generating: Ref<boolean>        // đang stream generate
streamDone: Ref<boolean>
error: Ref<string | null>

fetchPlan(scope?: 'daily'|'monthly')   // GET /plan
generate(scope?: 'daily'|'monthly')    // POST /plan/generate, parse SSE (giống useFoodAnalysis)
fetchHistory(scope?)                    // GET /plan/history
```

Logic parse SSE: tái dùng nguyên pattern `useFoodAnalysis` (fetch native + ReadableStream reader + buffer-aware line parser); chỉ khác event `type: "plan"` thay cho `"result"`.

### 10.2 Màn hình `MealPlan.vue`

**File:** `resources/js/pages/MealPlan.vue` (route `/plan`, `middleware: auth-strict`)

UI iOS-style:
- Header: tab `Ngày mai` / `Tháng này` (Phase 2 disable tab tháng).
- Nếu chưa có plan → empty state + nút "Tạo kế hoạch".
- Card tổng quan: vòng calo mục tiêu + macros (tái dùng `CalorieRing.vue`).
- Section "Bữa ăn": list bữa sáng/trưa/tối/phụ, mỗi bữa hiện món + calo + macros.
- Section "Tập luyện": list bài tập + thời lượng + calo đốt ước tính.
- Section "Lời khuyên AI": render `reasoning` (stream dần khi generating).
- Banner cam khi `isStale`: "Dữ liệu đã thay đổi — Cập nhật kế hoạch".
- Loading skeleton khi `generating && !plan`.

### 10.3 Điểm vào (entry points)
- Thêm mục "Kế hoạch" vào `BottomNav.vue` hoặc card "Kế hoạch ngày mai" trên `Home.vue` → `navigateTo('/plan')`.
- Trong `Profile.vue` (tùy chọn): link tới kế hoạch tháng.

---

## 11. TypeScript Types

**File:** `resources/js/types/plan.ts`

```typescript
export type PlanScope = 'daily' | 'monthly'
export type MealSlot = 'breakfast' | 'lunch' | 'dinner' | 'snack'
export type WorkoutType = 'cardio' | 'strength' | 'flexibility'
export type Intensity = 'low' | 'medium' | 'high'

export interface PlannedMeal {
  slot: MealSlot
  name: string
  items: string[]
  calories: number
  protein: number
  carbs: number
  fat: number
}

export interface PlannedWorkout {
  name: string
  type: WorkoutType
  duration_min: number
  intensity: Intensity
  est_calories_burned: number
}

export interface MealPlan {
  summary: string
  target_calories: number
  target_macros: { protein: number; carbs: number; fat: number }
  water_target_ml: number
  meals: PlannedMeal[]
  workouts: PlannedWorkout[]
  tips: string[]
}

export interface PlanResponse {
  plan: MealPlan | null
  reasoning?: string
  target_date?: string
  is_stale?: boolean
  needs_generation?: boolean
  reason?: 'stale' | 'missing'
  generated_at?: string
}

export type PlanStreamEvent =
  | { type: 'plan'; data: MealPlan }
  | { type: 'text'; delta: string }
  | { type: 'error'; message: string }
```

---

## 12. Danh sách file cần tạo / sửa

### Tạo mới

| File | Mô tả | Ưu tiên |
|------|--------|---------|
| `database/migrations/2026_06_25_000001_create_meal_plans_table.php` | Bảng lưu plan | 🔴 P0 |
| `app/Models/MealPlan.php` | Model + casts | 🔴 P0 |
| `app/Services/MealPlanService.php` | buildContext + Gemini structured/stream | 🔴 P0 |
| `app/Http/Controllers/Api/V1/PlanController.php` | show / generate (SSE) / history | 🔴 P0 |
| `resources/js/types/plan.ts` | TypeScript types | 🔴 P0 |
| `resources/js/composables/useMealPlan.ts` | fetch + parse SSE | 🔴 P0 |
| `resources/js/pages/MealPlan.vue` | Màn hình kế hoạch | 🔴 P0 |

### Sửa

| File | Việc cần làm | Ưu tiên |
|------|-------------|---------|
| `routes/api_v1.php` | Thêm nhóm `plan/*` | 🔴 P0 |
| `app/Models/User.php` | Thêm `mealPlans()` HasMany | 🔴 P0 |
| `resources/js/pages/Home.vue` | Card "Kế hoạch ngày mai" → /plan | 🟡 P1 |
| `resources/js/components/ios/BottomNav.vue` | Thêm tab/điểm vào Kế hoạch | 🟡 P1 |
| Router frontend | Đăng ký route `/plan` (auth-strict) | 🔴 P0 |

---

## 13. Checklist tổng

### Phase 1 — Daily plan (P0) ✅ HOÀN THÀNH

- [x] Spec viết xong
- [x] Migration `meal_plans` + `MealPlan` model + `User::mealPlans()`
- [x] `MealPlanService`
  - [x] `buildContext()` — BMR/TDEE (Mifflin) + avg/adherence/trend + data_hash
  - [x] `getStructuredPlan()` — Gemini JSON mode (daily schema)
  - [x] `streamReasoning()` — Gemini streaming Generator
  - [x] Validate profile đủ field → throw nếu thiếu
- [x] `PlanController`
  - [x] `show()` — trả plan + cờ is_stale/needs_generation
  - [x] `generate()` — SSE 2-phase + upsert DB + lưu reasoning
  - [x] `history()`
- [x] Routes nhóm `plan/*` (auth + throttle 5/min cho generate)
- [x] `resources/js/types/plan.ts`
- [x] `useMealPlan.ts` — fetch + SSE parser
- [x] `MealPlan.vue` — tabs, overview, meals, workouts, reasoning stream, stale banner, skeleton, error
- [x] Đăng ký route `/plan` + điểm vào từ Home (card "Tư vấn kế hoạch ngày mai")

> **Lưu ý:** cần chạy `php artisan migrate` (PHP 8.4 của Laragon) để tạo bảng `meal_plans`.

### Phase 2 — Monthly plan ✅ HOÀN THÀNH (trừ scheduled job)

- [x] Monthly schema (`weekly_focus`, `expected_weight_change_kg`, `weekly_workout_split`)
- [x] `getStructuredPlan()` nhánh monthly + prompt riêng (30 ngày dữ liệu)
- [x] MealPlan.vue tab "Tháng này" bật
- [ ] (Tùy chọn) Scheduled job tối tạo daily plan cho user có streak — dùng cron đã có

### Phase 3 — Đối chiếu thực hiện (P2) 🔴

- [ ] Bảng `activity_logs` (log tập luyện thực tế)
- [ ] So sánh kế hoạch vs thực tế (calo nạp/đốt) → hiển thị % hoàn thành kế hoạch
- [ ] Feedback vào prompt: "hôm qua bạn hoàn thành 80% kế hoạch"

---

## 14. Notes kỹ thuật

**Tái dùng tối đa hạ tầng có sẵn:**
- AI: `MealPlanService` clone cấu trúc `FoodAnalysisService` (Guzzle + Gemini, cùng config key).
- SSE: controller dùng đúng pattern `FoodController` (`ob_end_clean` + `flush`, `X-Accel-Buffering: no`).
- Frontend SSE parser: copy logic buffer-aware từ `useFoodAnalysis.ts`.

**Tiết kiệm token AI:**
- Không gọi AI khi mở app — chỉ khi `generate`. Plan persist trong DB.
- `data_hash` để biết khi nào nên gợi ý tạo lại, tránh sinh thừa.
- `throttle:5,1` cho `generate`.

**BMR/TDEE:** Mifflin-St Jeor. `activity_factor` mặc định 1.375 (vận động nhẹ); có thể cho người dùng chọn mức vận động ở Phase sau.

**Thiếu dữ liệu:**
- Profile thiếu chiều cao/cân nặng/năm sinh → 422, frontend điều hướng tới `/profile` hoàn thiện hồ sơ.
- Chưa có `meal_logs` (user mới) → context dùng `calorie_goal` làm mốc, prompt ghi rõ "chưa có lịch sử, lập kế hoạch chuẩn theo mục tiêu".

**Workout ở Phase 1 chỉ là đề xuất** — chưa có bảng log tập luyện. Không hiển thị "đã hoàn thành" cho đến Phase 3.

---

*File này được cập nhật mỗi khi hoàn thành một task. Kiểm tra checklist trước khi bắt đầu làm.*
