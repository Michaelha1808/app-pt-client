# Spec: Food Analysis — Nhận diện món ăn bằng AI

> **App:** CaloEye — Vue 3 SPA + Laravel 13 + Tailwind CSS 4 (iOS-style PWA)
> **Cập nhật lần cuối:** 2026-06-20
> **Trạng thái tổng:** ✅ Phase 1 hoàn thành — Chờ thêm OPENAI_API_KEY vào .env để test

---

## Mục lục
1. [Tổng quan kiến trúc](#1-tổng-quan-kiến-trúc)
2. [API Contract (Backend)](#2-api-contract-backend)
3. [Backend — FoodController + FoodAnalysisService](#3-backend--foodcontroller--foodanalysisservice)
4. [Frontend — Composable `useFoodAnalysis`](#4-frontend--composable-usefoodanalysis)
5. [Scan.vue — Nén ảnh](#5-scanvue--nén-ảnh)
6. [Result.vue — Streaming thực](#6-resultvue--streaming-thực)
7. [TypeScript Types](#7-typescript-types)
8. [Danh sách file cần tạo / sửa](#8-danh-sách-file-cần-tạo--sửa)
9. [Checklist tổng](#9-checklist-tổng)

---

## 1. Tổng quan kiến trúc

```
[Scan.vue]
  ├── Camera: getUserMedia → canvas capture → resize 800px → sessionStorage
  └── Gallery: File input → canvas resize 800px → sessionStorage
         │
         ▼ navigateTo('/result')
[Result.vue]
  └── onMounted → useFoodAnalysis.analyze()
         │
         ▼ POST /api/v1/food/analyze
[FoodController]
  └── FoodAnalysisService
        ├── Phase A: getStructuredData() — gọi OpenAI (JSON mode, ~500ms)
        │     └── emit SSE: {"type":"result","data":{...}}
        └── Phase B: streamAdvice() — gọi OpenAI (streaming)
              └── emit SSE: {"type":"text","delta":"..."} × N
                    └── emit: data: [DONE]
```

**Lý do thiết kế 2-phase:**
- Người dùng thấy calo + macros ngay (~500ms) trong khi text advice đang stream
- Tách biệt structured data (cần chính xác) và narrative (cần tự nhiên)
- Tránh parse JSON từ stream (phức tạp, dễ lỗi)

**AI Model:** OpenAI GPT-4o-mini
- Tốc độ nhanh (~500ms first token), chi phí thấp (~$0.0003/lần)
- Hỗ trợ vision (nhận diện ảnh) + JSON mode + streaming
- Nhận diện tốt đồ ăn Việt Nam
- PHP SDK: `openai-php/client`

**Phạm vi Phase 1:**
- ✅ Nén ảnh trước khi upload (frontend)
- ✅ POST /api/v1/food/analyze — SSE streaming response
- ✅ Nhận diện từ ảnh chụp / ảnh gallery
- ✅ Nhập mô tả thủ công → AI tính calo
- ✅ Result.vue kết nối API thực (thay setInterval fake)
- ❌ Lưu bữa ăn vào DB → Phase 2
- ❌ `today_calories` động từ lịch sử → Phase 2 (hiện tại = 0)

---

## 2. API Contract (Backend)

### POST `/api/v1/food/analyze`

**Auth:** Không bắt buộc (guest được phép scan). Rate limit: 10 req/phút/IP.

```json
// Request Body
{
  "image": "data:image/jpeg;base64,...",  // base64 đầy đủ với prefix (nullable)
  "text":  "1 bát phở bò lớn ~500ml",     // mô tả thủ công (nullable)
  "context": {
    "today_calories": 0,                  // calo đã ăn hôm nay (default 0, Phase 2 sẽ tự động)
    "goal": 2000                          // mục tiêu calo ngày (lấy từ user profile)
  }
}
```

**Validation:**
- `image` hoặc `text` phải có ít nhất 1
- `text`: min 3, max 500 ký tự
- `image`: base64 hợp lệ, tối đa ~4MB (tương đương ảnh 800px JPEG)
- `context.today_calories`: integer, 0–10000
- `context.goal`: integer, 1000–5000

**Response headers:**
```
Content-Type: text/event-stream; charset=utf-8
Cache-Control: no-cache, no-store
X-Accel-Buffering: no
Connection: keep-alive
```

**SSE event stream:**
```
data: {"type":"result","data":{...FoodAnalysisResult}}

data: {"type":"text","delta":"Phở bò tái chín là..."}
data: {"type":"text","delta":" món ăn truyền thống..."}
...

data: [DONE]
```

**Event `type: "result"` — FoodAnalysisResult:**
```json
{
  "food_name": "Phở bò tái chín",
  "serving":   "1 tô (~500ml)",
  "calories":  450,
  "protein":   28,
  "carbs":     56,
  "fat":       8,
  "sodium":    1200,
  "confidence": 0.92,
  "advice_short": "Giàu protein, cẩn thận lượng natri cao"
}
```

**Lỗi (non-stream, JSON):**
```json
// 400 — thiếu image và text
{ "message": "Phải cung cấp ảnh hoặc mô tả món ăn" }

// 422 — validation fail
{ "message": "Dữ liệu không hợp lệ", "errors": { "text": ["Mô tả quá ngắn"] } }

// 500 — AI API lỗi
{ "message": "Không thể kết nối dịch vụ AI. Vui lòng thử lại." }
```

**Event `type: "error"` (trong stream, sau khi headers đã gửi):**
```json
data: {"type":"error","message":"Không thể phân tích món ăn. Vui lòng thử lại."}
```

---

## 3. Backend — FoodController + FoodAnalysisService

### 3.1 FoodAnalysisService

**File:** `app/Services/FoodAnalysisService.php`

**Method `getStructuredData()`:**
- Input: `$image` (base64|null), `$text` (string|null), `$context` (array)
- OpenAI call: model `gpt-4o-mini`, `response_format: json_object`, `max_tokens: 300`
- Image detail: `low` (đủ để nhận diện, tiết kiệm token)
- Output: array với keys `food_name, serving, calories, protein, carbs, fat, sodium, confidence, advice_short`

**System prompt (getStructuredData):**
```
Bạn là chuyên gia dinh dưỡng AI chuyên về ẩm thực Việt Nam.
CHỈ trả về JSON hợp lệ, không giải thích thêm bất kỳ điều gì.
Ước tính cho 1 khẩu phần thông thường của món ăn đó.
```

**User prompt (getStructuredData):**
```
Phân tích món ăn [từ ảnh | mô tả: "{text}"] và trả về JSON:
{
  "food_name": "tên món tiếng Việt",
  "serving": "mô tả khẩu phần (vd: 1 tô ~500ml)",
  "calories": <số nguyên>,
  "protein": <gram>,
  "carbs": <gram>,
  "fat": <gram>,
  "sodium": <mg>,
  "confidence": <0.0–1.0>,
  "advice_short": "nhận xét dinh dưỡng ngắn 1 câu"
}

Ngữ cảnh: Người dùng đã ăn {today_calories} kcal hôm nay, mục tiêu {goal} kcal/ngày.
```

**Method `streamAdvice()`:**
- Input: `$foodName`, `$calories`, `$context`
- OpenAI call: model `gpt-4o-mini`, streaming, `max_tokens: 300`
- Output: `StreamResponse` (iterable)

**System prompt (streamAdvice):**
```
Bạn là trợ lý dinh dưỡng thân thiện của app CaloEye.
Trả lời bằng tiếng Việt, ngắn gọn, tự nhiên, không dùng markdown heading.
Có thể dùng emoji phù hợp.
```

**User prompt (streamAdvice):**
```
Món: {foodName} (~{calories} kcal/khẩu phần).
Hôm nay đã ăn: {today} kcal / mục tiêu {goal} kcal.
Sau khi ăn món này: {afterEating} kcal.

Viết lời khuyên dinh dưỡng (3–4 câu):
1. Điểm mạnh / điểm yếu dinh dưỡng của món
2. Tác động đến mục tiêu calo hôm nay
3. Một gợi ý thực tế (ăn kèm gì, tránh gì, thời điểm ăn tốt nhất)
```

### 3.2 FoodController

**File:** `app/Http/Controllers/Api/V1/FoodController.php`

Logic trong `analyze()`:
1. Validate request
2. `return response()->stream(function() { ... })` với headers SSE
3. Trong closure: `while (ob_get_level()) ob_end_clean()`
4. Gọi `getStructuredData()` → emit `result` event → `flush()`
5. Gọi `streamAdvice()` → foreach stream chunks → emit `text` events → `flush()`
6. Emit `data: [DONE]` → `flush()`
7. Wrap toàn bộ trong `try/catch` → emit `error` event nếu fail

### 3.3 Config AI

**`config/services.php`** — thêm:
```php
'openai' => [
    'key' => env('OPENAI_API_KEY'),
],
```

**`.env` / `.env.example`** — thêm:
```
OPENAI_API_KEY=sk-...
```

---

## 4. Frontend — Composable `useFoodAnalysis`

**File:** `resources/js/composables/useFoodAnalysis.ts`

**State được export:**
```typescript
result: Ref<FoodAnalysisResult | null>   // structured data từ AI
streamingText: Ref<string>               // advice text đang stream
streamDone: Ref<boolean>                 // stream hoàn tất
loading: Ref<boolean>                    // đang gọi API
error: Ref<string | null>                // lỗi (nếu có)
```

**Method `analyze(options)`:**
```typescript
options: {
  image?: string | null  // base64 từ sessionStorage
  text?: string | null   // từ route.query.food
  context: FoodAnalysisContext
}
```

**Flow trong `analyze()`:**
1. Reset state
2. `fetch(API_URL/food/analyze, { method: POST, body: JSON })` với `Authorization: Bearer token`
3. Nếu response không OK → parse error JSON → set `error`
4. Đọc `response.body` dạng `ReadableStream` qua `getReader()`
5. Mỗi chunk: decode UTF-8, split theo `\n`, parse SSE lines
6. `type: "result"` → set `result.value`
7. `type: "text"` → append `streamingText.value`
8. `type: "error"` → set `error.value`
9. `[DONE]` → set `streamDone.value = true`

**Lưu ý kỹ thuật:**
- Dùng `fetch()` native thay vì `apiFetch` (ofetch không hỗ trợ streaming)
- Buffer incomplete SSE lines giữa các chunk (split `\n`, giữ lại dòng cuối chưa có `\n`)
- Token từ `useAuthStore().token` (guest không có token → không gửi header)

---

## 5. Scan.vue — Nén ảnh

**File:** `resources/js/pages/Scan.vue`

### 5.1 Camera capture

**Hiện tại:** Chụp full resolution (1920×1080), quality 0.85 → ~300KB–1MB

**Thay đổi** trong `capture()`:
```javascript
const MAX_DIM = 800
const vw = videoEl.value.videoWidth || 1280
const vh = videoEl.value.videoHeight || 720
const scale = Math.min(1, MAX_DIM / Math.max(vw, vh))
canvas.width  = Math.round(vw * scale)
canvas.height = Math.round(vh * scale)
canvas.getContext('2d')?.drawImage(videoEl.value, 0, 0, canvas.width, canvas.height)
sessionStorage.setItem('scan_image', canvas.toDataURL('image/jpeg', 0.80))
```

Kết quả: ~800×450px, ~50–120KB (giảm 80–90% so với trước)

### 5.2 Gallery pick

**Hiện tại:** `FileReader.readAsDataURL()` trực tiếp — không resize, ảnh từ thư viện thường 4–12MB

**Thay đổi** trong `onGalleryPick()`: resize qua canvas trước khi lưu sessionStorage (cùng logic MAX_DIM=800, quality 0.80)

---

## 6. Result.vue — Streaming thực

**File:** `resources/js/pages/Result.vue`

**Những gì thay đổi:**

| Biến cũ | Nguồn mới |
|---------|-----------|
| `foodName` (hardcode) | `result.value?.food_name` |
| `calories` (hardcode 450) | `result.value?.calories` |
| `macros` (hardcode array) | Computed từ `result.value` |
| `streamingText` (setInterval fake) | `useFoodAnalysis().streamingText` |
| `streamDone` (fake) | `useFoodAnalysis().streamDone` |
| `todayConsumed` (hardcode 1340) | `useAuthStore().user?.today_calories ?? 0` (Phase 2) / `0` tạm thời |
| `todayGoal` (hardcode 2000) | `useAuthStore().user?.calorie_goal ?? 2000` |

**onMounted:**
1. Đọc `image = sessionStorage.getItem('scan_image')` (camera/gallery)
2. Đọc `text = route.query.food as string` (manual)
3. Đọc `goal` từ auth store user profile
4. Gọi `analyze({ image, text, context: { today_calories: 0, goal } })`
5. Sau khi navigate xong: `sessionStorage.removeItem('scan_image')`

**Error state:** Hiển thị card lỗi nếu `error.value` có giá trị, với nút "Thử lại".

**Loading skeleton:** Trong khi `loading && !result`: hiển thị skeleton cho card food name + calories.

---

## 7. TypeScript Types

**File:** `resources/js/types/food.ts`

```typescript
export interface FoodAnalysisResult {
  food_name: string
  serving: string
  calories: number
  protein: number
  carbs: number
  fat: number
  sodium: number
  confidence: number
  advice_short: string
}

export interface FoodAnalysisContext {
  today_calories: number
  goal: number
}

export type FoodStreamEvent =
  | { type: 'result'; data: FoodAnalysisResult }
  | { type: 'text'; delta: string }
  | { type: 'error'; message: string }
```

---

## 8. Danh sách file cần tạo / sửa

### Tạo mới

| File | Mô tả | Ưu tiên |
|------|--------|---------|
| `app/Services/FoodAnalysisService.php` | AI service: structured + streaming | 🔴 P0 |
| `app/Http/Controllers/Api/V1/FoodController.php` | SSE endpoint controller | 🔴 P0 |
| `resources/js/types/food.ts` | TypeScript types | 🔴 P0 |
| `resources/js/composables/useFoodAnalysis.ts` | Composable: fetch + parse SSE stream | 🔴 P0 |

### Sửa

| File | Việc cần làm | Ưu tiên |
|------|-------------|---------|
| `routes/api_v1.php` | Thêm `POST /food/analyze` | 🔴 P0 |
| `config/services.php` | Thêm `openai.key` | 🔴 P0 |
| `.env.example` | Thêm `OPENAI_API_KEY` | 🔴 P0 |
| `resources/js/pages/Scan.vue` | Resize canvas 800px (camera + gallery) | 🔴 P0 |
| `resources/js/pages/Result.vue` | Kết nối useFoodAnalysis, bỏ hardcode | 🔴 P0 |

### Cài package

| Package | Lý do |
|---------|-------|
| `openai-php/client` | PHP SDK để gọi OpenAI API (vision + streaming + JSON mode) |

---

## 9. Checklist tổng

### Phase 1 — Core Scan → AI → Stream ✅ HOÀN THÀNH

> **Ghi chú:** openai-php/client không install được do không có internet trong môi trường dev.
> Thay thế bằng Guzzle HTTP (đã có sẵn trong Laravel) gọi trực tiếp OpenAI REST API.

- [x] Spec viết xong
- [x] Thêm `OPENAI_API_KEY` vào `.env.example` + `config/services.php`
- [x] Tạo `app/Services/FoodAnalysisService.php` (Guzzle, không cần SDK)
  - [x] `getStructuredData(image, text, context)` — JSON mode
  - [x] `streamAdvice(foodName, calories, context)` — streaming Generator
- [x] Tạo `app/Http/Controllers/Api/V1/FoodController.php`
  - [x] `analyze()` — validate + SSE stream (2-phase)
- [x] Thêm route `POST /food/analyze` (throttle 10/min, no auth) vào `routes/api_v1.php`
- [x] Tạo `resources/js/types/food.ts`
- [x] Tạo `resources/js/composables/useFoodAnalysis.ts`
  - [x] fetch + ReadableStream reader
  - [x] SSE line parser (buffer-aware)
  - [x] reactive state: result, streamingText, streamDone, loading, error
- [x] Sửa `resources/js/pages/Scan.vue`
  - [x] camera capture: canvas resize MAX_DIM=800, quality 0.80
  - [x] gallery pick: canvas resize trước khi sessionStorage
- [x] Sửa `resources/js/pages/Result.vue`
  - [x] Import và dùng `useFoodAnalysis`
  - [x] onMounted: đọc sessionStorage/query → gọi analyze()
  - [x] Template: bind result, streamingText, streamDone
  - [x] Loading skeleton khi chờ result
  - [x] Error card với nút "Thử lại"
  - [x] Xóa sessionStorage sau khi load

**Để test Phase 1:** Thêm `OPENAI_API_KEY=sk-...` vào `.env` rồi restart server.

### Phase 2 — Lưu bữa ăn + Lịch sử ✅ HOÀN THÀNH

- [x] Migration: bảng `meal_logs` (user_id, food_name, serving, calories, protein, carbs, fat, sodium, logged_at)
- [x] `MealLog` model + relationship `User::mealLogs()`
- [x] `POST /api/v1/food/log` — lưu bữa ăn sau khi confirm (auth required)
- [x] `GET /api/v1/food/today` — lấy stats + danh sách bữa ăn hôm nay (auth required)
- [x] `useMealLog` composable — `fetchTodayStats()`, `logMeal()`
- [x] Result.vue `confirmMeal()` — gọi `logMeal()` API thật
- [x] Result.vue — fetch `today_calories` thực trước khi analyze
- [x] Home.vue — hiển thị danh sách bữa ăn thật từ API, tên user thật, macros tính từ meal_logs

### Phase 3 — Polish ✅ HOÀN THÀNH

- [x] Barcode scan — native `BarcodeDetector` API (Chrome/Android) → Open Food Facts API → bypass AI, thẳng vào Result.vue qua `sessionStorage.barcode_result`
- [x] Rate limiting backend — `throttle:10,1` đã có từ Phase 2
- [x] Confidence thấp (<0.5): hiển thị warning card màu cam "AI không chắc chắn" trong Result.vue
- [x] Cho phép chỉnh sửa tên món / calo inline trong Result.vue trước khi confirm

### Phase 4 — Lịch sử thật ✅ HOÀN THÀNH

- [x] `GET /api/v1/food/history?date=YYYY-MM-DD` — 7 ngày gần nhất + meals theo ngày chọn
- [x] `DELETE /api/v1/food/log/{log}` — xóa bữa ăn (auth + ownership check)
- [x] History.vue — kết nối API thật: 7-day strip, bar chart, summary card, danh sách bữa ăn có xóa
- [x] `useMealLog` — thêm `fetchHistory(date?)` và `deleteLog(id)`

---

## Notes kỹ thuật

**PHP Streaming trên Nginx/Laragon:**
- `X-Accel-Buffering: no` bắt buộc khi đứng sau Nginx proxy
- `while (ob_get_level()) ob_end_clean()` trước khi flush đầu tiên
- Không dùng `ob_flush()` — chỉ dùng `flush()` sau khi tắt output buffering

**sessionStorage vs localStorage:**
- Dùng `sessionStorage` (tab-scoped) — ảnh không lưu qua tab khác, tự xóa khi đóng tab
- Xóa `scan_image` ngay trong `onMounted` của Result.vue sau khi đọc để tránh dữ liệu cũ

**Token cho guest:**
- Guest không có `store.token` → gọi API không có `Authorization` header
- Backend không yêu cầu auth cho endpoint này (rate limit là đủ bảo vệ)

**Image base64 size:**
- 800×450px JPEG q=0.80 ≈ 60–120 KB → base64 ≈ 80–160 KB
- GPT-4o-mini với `detail: "low"` resize về 512px internally — đủ để nhận diện món ăn

---

*File này được cập nhật mỗi khi hoàn thành một task. Kiểm tra checklist trước khi bắt đầu làm.*
