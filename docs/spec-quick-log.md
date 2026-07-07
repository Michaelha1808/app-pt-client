# Spec: Quick Log — Ăn lại món cũ + Món yêu thích

> **App:** CaloEye — Laravel + Vue 3 (Vite) + Tailwind CSS 4 (iOS-style PWA)
> **Cập nhật lần cuối:** 2026-07-08
> **Trạng thái tổng:** ✅ TẤT CẢ PHASE HOÀN THÀNH — verify Docker (PHP 8.4/Postgres) + Playwright UI

---

## Mục lục
1. [Tổng quan & lý do](#1-tổng-quan--lý-do)
2. [Kiến trúc](#2-kiến-trúc)
3. [Database](#3-database)
4. [API Contract (Backend)](#4-api-contract-backend)
5. [Frontend](#5-frontend)
6. [Gợi ý theo khung giờ](#6-gợi-ý-theo-khung-giờ)
7. [Danh sách file cần tạo / sửa](#7-danh-sách-file-cần-tạo--sửa)
8. [Checklist theo phase](#8-checklist-theo-phase)

---

## 1. Tổng quan & lý do

Hiện tại **mọi** lần ghi bữa ăn đều phải đi qua chụp ảnh AI (`/scan` → `/result`) hoặc nhập text để AI phân tích — tốn thời gian và tốn quota Gemini, trong khi người dùng ăn lặp lại rất nhiều (cơm nhà, phở quán quen, cà phê sữa mỗi sáng). Friction ghi log hàng ngày là yếu tố quyết định retention.

Tính năng gồm 3 phần:
1. **Ăn lại (re-log)**: từ lịch sử / danh sách hôm nay, 1 chạm ghi lại món với đúng dinh dưỡng cũ — **không gọi AI**.
2. **Món yêu thích (favorites)**: user lưu món hay ăn thành danh sách riêng, log lại 1 chạm từ tab "Món của tôi".
3. **Món thường ăn (frequent)**: tự tổng hợp từ `meal_logs` 30 ngày (không cần user lưu), gợi ý theo khung giờ hiện tại.

**Nguyên tắc:** re-log là **bản ghi meal_log mới** với `logged_at = now()`, copy dinh dưỡng từ nguồn (log cũ / favorite). Ảnh: tham chiếu lại `image_path` cũ (không copy file). Mọi đường ghi log đều đi qua flow sẵn có → streak (`StreakService::recordMealActivity`) và habit cache (`PreferenceService::bustHabitCache`) hoạt động tự nhiên.

---

## 2. Kiến trúc

```
[Vue SPA]                                    [Laravel API /api/v1] (đều auth:sanctum)
   │
   │── POST   /food/relog/{log} ────────────▶  copy meal_log → bản ghi mới now()
   │── GET    /food/frequent?slot=morning ──▶  top món 30 ngày (group + đếm), lọc khung giờ
   │
   │── GET    /food/favorites ──────────────▶  danh sách món yêu thích
   │── POST   /food/favorites ──────────────▶  lưu món (từ log có sẵn hoặc nhập tay)
   │── POST   /food/favorites/{fav}/log ────▶  ghi meal_log từ favorite
   │── DELETE /food/favorites/{fav} ────────▶  bỏ yêu thích
```

- Routes đặt trong nhóm `auth:sanctum` sẵn có của food (`routes/api_v1.php`, cạnh `/food/log`).
- `relog` và `favorites/{fav}/log` tái dùng logic của `FoodController::log` (tách phần tạo log + streak + bust cache thành method/service dùng chung để tránh lặp).

---

## 3. Database

### Migration `create_favorite_meals_table`

```php
Schema::create('favorite_meals', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('food_name');
    $table->string('serving')->nullable();
    $table->integer('calories');
    $table->integer('protein')->default(0);
    $table->integer('carbs')->default(0);
    $table->integer('fat')->default(0);
    $table->integer('sodium')->default(0);
    $table->string('image_path')->nullable();     // tham chiếu ảnh của meal_log gốc
    $table->timestamps();

    $table->unique(['user_id', 'food_name', 'serving']);   // chống trùng
    $table->index('user_id');
});
```

Snapshot dinh dưỡng (copy giá trị, không FK sang meal_logs) — meal_log gốc bị xoá thì favorite vẫn dùng được. Giới hạn tối đa **50 favorites/user** (validate khi store).

### Model `App\Models\FavoriteMeal`
- `$fillable`: các cột trên. Quan hệ `User::favoriteMeals()`.
- Accessor `image_url` giống `MealLog` (đọc từ `image_path`).

---

## 4. API Contract (Backend)

### 4.1 POST `/food/relog/{log}`
Ghi lại 1 meal_log cũ thành bữa mới ngay bây giờ. Chỉ log của chính user (404 nếu không phải).

```json
// Request: (không body — hoặc optional override)
{ "serving": "1 tô lớn" }        // sometimes; mặc định copy nguyên

// Response 201 (shape giống POST /food/log)
{ "message": "Đã lưu bữa ăn", "id": 345, "streak": { ... } }
```

### 4.2 GET `/food/frequent?slot=morning&limit=8`
- `slot` optional ∈ {morning, noon, evening} — bỏ trống = mọi khung giờ. `limit` mặc định 8, max 20.
- Tổng hợp `meal_logs` 30 ngày: group theo (`food_name` normalize — dùng `App\Support\VietnameseText` sẵn có, `serving`), đếm số lần, lấy bản ghi gần nhất làm đại diện dinh dưỡng.
- Chỉ trả món ăn ≥ 2 lần (1 lần chưa gọi là "thường ăn").

```json
// Response 200
{
  "items": [
    {
      "food_name": "Phở bò", "serving": "1 tô", "count": 9,
      "calories": 450, "protein": 25, "carbs": 55, "fat": 12, "sodium": 1200,
      "image_url": "https://…/meals/abc.jpg",
      "last_log_id": 340,                 // dùng cho POST /food/relog/{id}
      "is_favorite": false
    }
  ]
}
```

Cache 10 phút/user/slot (bust chung chỗ `PreferenceService::bustHabitCache` hoặc key riêng bust khi ghi log mới).

### 4.3 GET `/food/favorites`
```json
// Response 200
{ "items": [ { "id": 3, "food_name": "Cà phê sữa", "serving": "1 ly", "calories": 120,
               "protein": 2, "carbs": 18, "fat": 4, "sodium": 30, "image_url": null } ] }
```

### 4.4 POST `/food/favorites`
```json
// Request — cách 1: từ meal_log có sẵn
{ "meal_log_id": 340 }
// Request — cách 2: nhập trực tiếp (từ frequent item hoặc form)
{ "food_name": "Phở bò", "serving": "1 tô", "calories": 450,
  "protein": 25, "carbs": 55, "fat": 12, "sodium": 1200 }

// Response 201
{ "item": { "id": 4, ... } }
// Response 409 — đã tồn tại (unique user+food_name+serving)
{ "detail": "Món này đã có trong danh sách yêu thích" }
// Response 422 — quá 50 món
{ "detail": "Tối đa 50 món yêu thích" }
```
Validation dinh dưỡng khớp `FoodController::log` (`calories 0–10000`…).

### 4.5 POST `/food/favorites/{fav}/log`
Ghi meal_log mới từ favorite (copy dinh dưỡng + image_path, `logged_at = now()`). Response 201 giống 4.1.

### 4.6 DELETE `/food/favorites/{fav}`
Response 204. Chỉ chủ sở hữu.

---

## 5. Frontend

### 5.1 Nút "Ăn lại" — `History.vue` + `Home.vue`
- Mỗi meal item trong lịch sử (và danh sách bữa hôm qua trở về trước ở History) thêm nút/ swipe-action **"Ăn lại"** → gọi `POST /food/relog/{id}` → toast "Đã thêm Phở bò vào hôm nay" + cập nhật ring calo nếu đang ở Home.
- Kèm nút ⭐ (toggle favorite) trong meal detail/sheet: gọi 4.4 / 4.6.

### 5.2 Tab "Món của tôi" — màn `/scan` (`Scan.vue`)
`/scan` hiện là camera + nhập text. Thêm **segmented control / tab thứ 3: "Món của tôi"**:
1. **Hàng chip gợi ý theo khung giờ** (frequent, mục 6): "Bạn hay ăn giờ này" — chạm 1 phát = log luôn (`relog` bằng `last_log_id`).
2. **Section Yêu thích**: grid card (ảnh, tên, kcal) + nút log nhanh + xoá ⭐.
3. **Section Thường ăn** (frequent toàn bộ): tương tự + nút ⭐ để thăng cấp thành favorite.
4. Empty state: "Chưa có món nào — hãy scan vài bữa, món bạn ăn thường xuyên sẽ xuất hiện ở đây."

### 5.3 Quick-add trên Home
Dưới danh sách bữa hôm nay: hàng chip ngang tối đa 4 món frequent theo khung giờ hiện tại (ẩn nếu rỗng). Chạm → confirm sheet nhỏ (tên + kcal + nút Ghi) → relog.

### 5.4 Composable `useQuickLog.ts`
`frequent(slot?)`, `relog(logId, override?)`, `favorites()`, `addFavorite(payload)`, `logFavorite(id)`, `removeFavorite(id)` — bọc `apiFetch`.

---

## 6. Gợi ý theo khung giờ

Phân bucket theo `logged_at` (giờ local user — server dùng giờ app hiện hành, đồng bộ với logic noti sáng/tối sẵn có):

| Slot | Khung giờ log gốc | Hiển thị khi giờ hiện tại |
|------|-------------------|---------------------------|
| `morning` | 04:00–10:59 | 04:00–10:59 |
| `noon` | 11:00–16:59 | 11:00–16:59 |
| `evening` | 17:00–03:59 | 17:00–03:59 |

Query frequent thêm điều kiện giờ khi có `slot`. FE tự xác định slot hiện tại và truyền lên — backend không đoán.

---

## 7. Danh sách file cần tạo / sửa

**Tạo mới**
| File | Nội dung |
|------|----------|
| `database/migrations/2026_07_07_000004_create_favorite_meals_table.php` | Bảng favorite_meals |
| `app/Models/FavoriteMeal.php` | Model |
| `app/Services/QuickLogService.php` | `frequent()` — group theo tên chuẩn hoá + serving, slot filter, đánh dấu `is_favorite` |
| `app/Http/Controllers/Api/V1/FavoriteController.php` | index/store/logFavorite/destroy (tách riêng khỏi FoodController) |
| `resources/js/composables/useQuickLog.ts` | frequent/favorites API wrapper |
| `tests/Feature/QuickLogControllerTest.php` | 13 feature test |

**Sửa**
| File | Thay đổi |
|------|----------|
| `routes/api_v1.php` | 6 routes mới trong nhóm food auth |
| `app/Http/Controllers/Api/V1/FoodController.php` | Thêm actions `relog`, `frequent` |
| `app/Models/User.php` | Quan hệ `favoriteMeals()` |
| `resources/js/composables/useMealLog.ts` | Thêm `relogMeal()` |
| `resources/js/pages/Scan.vue` | Tab thứ 3 "Món của tôi" (chip theo giờ + Yêu thích + Thường ăn) |
| `resources/js/pages/History.vue` | Swipe-action "Ăn lại" + "Xóa"; nút ⭐ thêm yêu thích |
| `resources/js/pages/Home.vue` | Hàng chip quick-add theo khung giờ + confirm sheet |

**Lệch so với spec ban đầu (quyết định khi implement):**
- Không tách "helper tạo meal_log dùng chung" — giữ nguyên quy ước hiện có của `FoodController` (log/logBatch đã tự lặp 2 dòng bustHabitCache+recordMealActivity thay vì trừu tượng hoá); `relog`/`logFavorite` làm theo đúng quy ước đó.
- Không cache 10 phút cho `frequent()` — query nhẹ (30 ngày, đã có index `[user_id, logged_at]`), thêm cache sẽ cần logic bust theo mỗi lần log mới mà lợi ích ở quy mô hiện tại không đáng kể.

---

## 8. Checklist theo phase

### Phase 1 — Re-log + Frequent (backend) ✅
- [x] ✅ POST `/food/relog/{log}` (ownership check → 404, copy image_path, override serving)
- [x] ✅ GET `/food/frequent` (`QuickLogService::frequent`, normalize `VietnameseText`, ≥2 lần, slot filter theo `whereTime`)
- [x] ✅ Feature test (`QuickLogControllerTest`, 13 test): relog đúng dinh dưỡng, không relog log người khác, frequent group đúng (kể cả không phân biệt hoa/dấu), lọc theo slot — PASS trên Docker (PHP 8.4 + Postgres thật)

### Phase 2 — Re-log (frontend) ✅
- [x] ✅ `useQuickLog.ts` + `useMealLog.relogMeal()`
- [x] ✅ Swipe-action "Ăn lại" ở `History.vue` (+ toast, streak cập nhật qua `onMealLogged`)
- [x] ✅ Chip quick-add theo khung giờ ở `Home.vue` + confirm sheet
- [x] ✅ Verify luồng thật bằng Playwright: ăn lại từ History và quick-add từ Home → toast thành công, dữ liệu cập nhật

### Phase 3 — Favorites ✅
- [x] ✅ Migration + Model + CRUD API (`FavoriteController`: store từ meal_log_id / payload, limit 50 → 422, trùng → 409)
- [x] ✅ POST `/food/favorites/{fav}/log`
- [x] ✅ Nút ⭐ ở `History.vue` (thêm yêu thích); tab "Món của tôi" ở `Scan.vue` (chip theo giờ + Yêu thích + Thường ăn + nút ⭐ thăng cấp + empty state)
- [x] ✅ Feature test favorites (409/422/404/204) + verify UI Playwright (screenshot xác nhận cả 3 section hiển thị đúng)

### Phase 4 — Đánh bóng ✅
- [x] ✅ `usage_events` type `quick_log_relog` và `quick_log_favorite` để đo tỉ lệ log qua đường nhanh vs AI scan
- [x] ✅ Gợi ý trong chat: xác nhận quick-log/relog tạo bản ghi `meal_logs` bình thường nên `ChatService::buildUserContext` tự động thấy được — không cần thay đổi thêm
