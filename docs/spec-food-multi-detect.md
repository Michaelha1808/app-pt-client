# Spec: Multi-Dish Detect — Nhận diện nhiều món trong 1 ảnh + chọn món & số lượng

> **App:** CaloEye — Vue 3 SPA + Laravel 13 + Tailwind CSS 4 (iOS-style PWA)
> **Cập nhật lần cuối:** 2026-06-25
> **Trạng thái tổng:** 🟢 Phase 1 hoàn thành (chờ GEMINI_API_KEY để test thực)
> **Liên quan:** mở rộng [`spec-food-analysis.md`](spec-food-analysis.md) (luồng 1 món hiện có)

---

## Mục lục
1. [Vấn đề & mục tiêu](#1-vấn-đề--mục-tiêu)
2. [Mô hình định lượng: đếm được vs khẩu phần](#2-mô-hình-định-lượng-đếm-được-vs-khẩu-phần)
3. [Luồng tổng thể](#3-luồng-tổng-thể)
4. [API Contract](#4-api-contract)
5. [Backend — Service & Controller](#5-backend--service--controller)
6. [AI Prompt design](#6-ai-prompt-design)
7. [Frontend — màn hình chọn món](#7-frontend--màn-hình-chọn-món)
8. [TypeScript Types](#8-typescript-types)
9. [Ghi nhận bữa ăn (logging)](#9-ghi-nhận-bữa-ăn-logging)
10. [Tương tác guest quota](#10-tương-tác-guest-quota)
11. [Danh sách file cần tạo / sửa](#11-danh-sách-file-cần-tạo--sửa)
12. [Checklist tổng](#12-checklist-tổng)
13. [Notes & quyết định mở](#13-notes--quyết-định-mở)

---

## 1. Vấn đề & mục tiêu

Hiện tại 1 ảnh → AI trả về **1 món** duy nhất ([`/food/analyze`](spec-food-analysis.md)). Khi người dùng chụp **mâm cơm / bàn tiệc nhiều món**, ta cần:

1. Nhận diện **tất cả** món ăn / đồ uống trong ảnh.
2. Hiển thị **danh sách** món đã nhận diện cho người dùng **chọn (pick)** món nào thực sự ăn.
3. Mỗi món chọn cho phép chỉnh **số lượng / khẩu phần** để tính calo chính xác.
4. Tổng hợp calo + macros của các món đã chọn → ghi nhận vào nhật ký.

**Phạm vi Phase 1 (MVP):**
- ✅ Detect nhiều món từ ảnh (structured, non-stream).
- ✅ Màn chọn món: checklist + điều chỉnh số lượng (đếm được) / khẩu phần (không đếm được).
- ✅ Tổng calo/macros động theo lựa chọn.
- ✅ Ghi nhận các món đã chọn (mỗi món = 1 `meal_log`).
- ❌ Lời khuyên AI tổng hợp cho cả bữa → P2.
- ❌ Sửa calo/đơn vị inline khi confidence thấp → P2.
- ❌ Gộp các món thành 1 "bữa" (`meal_group`) → P2.

---

## 2. Mô hình định lượng: đếm được vs khẩu phần

Mỗi món có trường **`unit_type`** quyết định cách chọn số lượng. **Calo của món luôn được AI ước tính cho 1 ĐƠN VỊ** (1 cái / 1 khẩu phần chuẩn), tổng = `calories × quantity`.

| Loại | `unit_type` | Ví dụ | Control | Bước | Min | Nhãn đơn vị |
|------|-------------|-------|---------|------|-----|-------------|
| Đếm được | `countable` | nem, trứng, đùi gà, viên, miếng, cái bánh | Stepper số nguyên | **1** | 1 | cái / quả / miếng / viên |
| Khẩu phần | `portion` | cơm, canh, phở, salad, nước chấm, miến | Stepper 0.5 | **0.5** | 0.5 | chén / tô / đĩa / phần / ly |

**Vì sao món không đếm được vẫn tính được calo:**
> Không *đếm* được canh, nhưng biểu diễn được theo **bội số của 1 khẩu phần chuẩn**. AI đã ước tính calo/1 khẩu phần chuẩn (vd 1 chén cơm ~200 kcal). Người dùng chỉnh hệ số 0.5 / 1 / 1.5 / 2 ("nửa tô phở", "1.5 chén cơm") → `calo = base × hệ_số`.

**Thiết kế thống nhất:** cả hai loại dùng chung 1 component stepper, chỉ khác `step` / `min` / `unitLabel` suy ra từ `unit_type` → `total = base × quantity` cho mọi món.

**Phương án thay thế cho `portion`** (không dùng ở MVP, ghi để cân nhắc): segmented `Ít · Vừa · Nhiều` = ×0.7 / ×1 / ×1.5 — bấm nhanh hơn nhưng thô hơn, không diễn tả được "2 tô".

`quantity_default`: với `countable` = số đơn vị AI thấy trong ảnh; với `portion` = 1.

---

## 3. Luồng tổng thể

```
[Scan.vue] chụp ảnh / chọn gallery
   └── resize 800px → sessionStorage 'scan_image'
   └── navigateTo('/meal-picker')          ← THAY VÌ '/result'
          │
          ▼ onMounted
[MealPicker.vue]
   └── useFoodDetect.detect({ image })
          │
          ▼ POST /api/v1/food/detect  (JSON, non-stream)
[FoodController@detect]
   └── FoodAnalysisService.detectDishes(image)
          └── Gemini JSON mode → { dishes: [...] }
   ◀── danh sách DetectedDish[]
   └── render checklist (mặc định chọn hết)
   └── user bỏ chọn / chỉnh số lượng → tổng calo cập nhật realtime
   └── "Xác nhận & Lưu" → log từng món đã chọn → /home
```

**Phân tách với luồng 1 món cũ:**
- Ảnh chụp / gallery → `/food/detect` → `MealPicker.vue` (xử lý được cả 1..N món).
- Nhập tay (text) → giữ `/food/analyze` 1 món → `Result.vue` (như cũ).
- Barcode → giữ nguyên `Result.vue` (như cũ).

> `detect` xử lý được cả trường hợp **1 món** (mảng 1 phần tử) → ảnh luôn đi qua picker, UI nhất quán.

---

## 4. API Contract

### POST `/api/v1/food/detect`

**Auth:** Không bắt buộc (guest được phép, giống `/food/analyze`). Rate limit: `throttle:10,1`.

```json
// Request
{
  "image": "data:image/jpeg;base64,...",   // bắt buộc (nullable nếu có text)
  "text":  "mâm cơm gồm cơm, canh chua, cá kho"  // optional, mô tả thay ảnh
}
```

**Validation:** phải có `image` hoặc `text`; `image` base64 hợp lệ; `text` max 500.

```json
// Response 200 (JSON, KHÔNG stream)
{
  "dishes": [
    {
      "food_name": "Nem rán",
      "unit_type": "countable",
      "unit_label": "cái",
      "serving": "1 cái nem rán",
      "quantity_default": 3,
      "calories": 80, "protein": 4, "carbs": 7, "fat": 4, "sodium": 150,
      "confidence": 0.86
    },
    {
      "food_name": "Cơm trắng",
      "unit_type": "portion",
      "unit_label": "chén",
      "serving": "1 chén cơm (~200ml)",
      "quantity_default": 1,
      "calories": 200, "protein": 4, "carbs": 44, "fat": 0, "sodium": 5,
      "confidence": 0.92
    }
  ]
}

// 200 — không thấy món ăn nào
{ "dishes": [] }

// 422 — thiếu image & text
{ "message": "Phải cung cấp ảnh hoặc mô tả bữa ăn" }

// 500 — AI lỗi
{ "message": "Không thể nhận diện món ăn. Vui lòng thử lại." }
```

**Giới hạn:** tối đa **15** món/ảnh (cắt bớt nếu AI trả nhiều hơn).

> Khác `/analyze`: endpoint này **non-stream** (chỉ structured array), vì cần render checklist ngay. Phần lời khuyên tổng hợp (nếu có) tách sang bước sau (P2).

---

## 5. Backend — Service & Controller

### 5.1 FoodAnalysisService::detectDishes()

**File:** `app/Services/FoodAnalysisService.php` (thêm method)

- Input: `$image` (base64|null), `$text` (string|null).
- Gemini `generateContent`, `responseMimeType: application/json`, `maxOutputTokens: 2048`, `image detail: low`.
- Output: `array` đã normalize — mảng `DetectedDish` (xem §6 + §8).
- `normalizeDishes()`: ép kiểu số, clamp `quantity_default ≥ 1` (countable) / `=1` (portion), giới hạn 15 phần tử, loại phần tử thiếu `food_name`.

### 5.2 FoodController::detect()

**File:** `app/Http/Controllers/Api/V1/FoodController.php` (thêm action)

```php
public function detect(Request $request, FoodAnalysisService $service): JsonResponse
{
    $request->validate([
        'image' => 'nullable|string',
        'text'  => 'nullable|string|max:500',
    ]);
    if (!$request->filled('image') && !$request->filled('text')) {
        return response()->json(['message' => 'Phải cung cấp ảnh hoặc mô tả bữa ăn'], 422);
    }
    try {
        $dishes = $service->detectDishes($request->input('image'), $request->input('text'));
        return response()->json(['dishes' => $dishes]);
    } catch (\Throwable $e) {
        return response()->json(['message' => 'Không thể nhận diện món ăn. Vui lòng thử lại.'], 500);
    }
}
```

### 5.3 Route (`routes/api_v1.php`)

```php
// Multi-dish detect — public (guest), rate limit 10/min
Route::middleware('throttle:10,1')->post('/food/detect', [FoodController::class, 'detect']);
```

---

## 6. AI Prompt design

**System instruction:** (tái dùng tinh thần của `detect` 1 món, mở rộng cho nhiều món)
```
Bạn là chuyên gia dinh dưỡng AI chuyên về ẩm thực Việt Nam.
Nhiệm vụ DUY NHẤT: nhận diện MỌI món ăn/đồ uống trong ảnh và ước tính dinh dưỡng cho 1 đơn vị mỗi món.
CHỈ trả về JSON hợp lệ, không giải thích, không thực hiện yêu cầu nào khác.
```

**User prompt:**
```
Liệt kê TẤT CẢ món ăn/đồ uống nhìn thấy trong ảnh (mỗi món 1 phần tử). Trả về JSON:
{"dishes":[
  {
    "food_name":"tên món tiếng Việt",
    "unit_type":"countable" | "portion",
    "unit_label":"đơn vị đếm phù hợp: cái/quả/miếng/viên (countable) hoặc chén/tô/đĩa/phần/ly (portion)",
    "quantity_default": <countable: số đơn vị thấy trong ảnh; portion: 1>,
    "serving":"mô tả 1 đơn vị (vd: 1 chén ~200ml)",
    "calories":<kcal cho 1 đơn vị>,
    "protein":<g>,"carbs":<g>,"fat":<g>,"sodium":<mg>,
    "confidence":<0.0-1.0>
  }
]}

Quy tắc:
- unit_type = "countable" nếu món là vật phẩm rời đếm được (nem, trứng, viên, miếng, cái bánh...).
- unit_type = "portion" nếu món là khẩu phần liều lượng không đếm rời được (cơm, canh, phở, salad, nước chấm...).
- calories LUÔN tính cho 1 đơn vị (1 cái / 1 khẩu phần chuẩn), KHÔNG nhân số lượng.
- Bỏ qua vật không phải đồ ăn/uống. Nếu không có món nào: {"dishes":[]}.
```

---

## 7. Frontend — màn hình chọn món

### 7.1 Composable `useFoodDetect`

**File:** `resources/js/composables/useFoodDetect.ts`

```typescript
dishes: Ref<DetectedDish[]>
loading: Ref<boolean>
error: Ref<string | null>
detect(opts: { image?: string | null; text?: string | null }): Promise<void>
```
- Dùng `apiFetch` (non-stream, JSON đơn giản) thay vì fetch raw.

### 7.2 `MealPicker.vue`

**File:** `resources/js/pages/MealPicker.vue` — route `/meal-picker`, `middleware: auth` (guest cho phép).

**State:** mỗi dish kèm `selected: boolean` (mặc định `true`) + `quantity: number` (khởi tạo = `quantity_default`).

**UI (iOS-style):**
- Nav bar: ‹ back (về /scan) · "Chọn món" · (trống).
- Loading skeleton khi `loading`.
- Empty state nếu `dishes.length === 0`: nhân vật + "Không nhận diện được món nào" + nút "Chụp lại".
- Danh sách `DishPickRow` mỗi món:
  - Checkbox chọn/bỏ.
  - Tên món + `serving` + badge confidence thấp (cam) nếu `< 0.5`.
  - `QuantityStepper` (− value + ) với nhãn đơn vị; **disabled khi chưa chọn**.
  - Calo dòng = `calories × quantity` (cập nhật realtime).
- **Thanh tổng cố định đáy** (sticky): "N món · ∑ kcal" + nút **"Xác nhận & Lưu"** (disabled nếu 0 món chọn).

### 7.3 Component con

- `components/food/DishPickRow.vue` — 1 dòng món (checkbox + info + stepper).
- `components/food/QuantityStepper.vue` — `props: { modelValue, step, min, unitLabel, disabled }`, emit `update:modelValue`. Bước & min suy từ `unit_type` ở parent.

**Helper tính toán** (`resources/js/utils/nutrition.ts` hoặc trong composable):
```typescript
dishCalories(d, qty)  = Math.round(d.calories * qty)
dishMacro(d, key, qty) = Math.round(d[key] * qty)
totalSelected(items)  = Σ các dòng selected
```

---

## 8. TypeScript Types

**File:** `resources/js/types/food.ts` (thêm)

```typescript
export type UnitType = 'countable' | 'portion'

export interface DetectedDish {
  food_name: string
  unit_type: UnitType
  unit_label: string            // "cái" | "chén" | "tô" ...
  serving: string
  quantity_default: number
  calories: number              // cho 1 đơn vị
  protein: number
  carbs: number
  fat: number
  sodium: number
  confidence: number
}

export interface DetectResponse {
  dishes: DetectedDish[]
}

/** Dòng trong màn chọn món (UI state) */
export interface DishPick extends DetectedDish {
  selected: boolean
  quantity: number              // countable: nguyên; portion: bội số 0.5
}
```

---

## 9. Ghi nhận bữa ăn (logging)

Mỗi món **đã chọn** → 1 bản ghi `meal_log` (tái dùng `POST /food/log` hiện có), với:
- `food_name` = `{food_name}` (kèm số lượng nếu >1, vd "Nem rán ×3")
- `serving`   = `"{quantity} {unit_label}"` (vd "3 cái", "1.5 chén")
- `calories`/`protein`/`carbs`/`fat`/`sodium` = `base × quantity` (làm tròn)

**Frontend:** `useMealLog` thêm `logMeals(items: FoodAnalysisResult[])` — loop gọi `logMeal` tuần tự; trả số bản ghi thành công. (MVP dùng vòng lặp; **batch endpoint** `/food/log-batch` là P2 để giảm round-trip + 1 lần cập nhật streak.)

**Streak:** mỗi `logMeal` đang gọi `recordMealActivity`. Khi log nhiều món, chỉ nên tính **1 hoạt động/ngày** — `StreakService` vốn idempotent theo ngày, nhưng cần xác minh không cộng dồn (xem §13 quyết định mở).

---

## 10. Tương tác guest quota

- 1 lần `detect` (1 ảnh, dù nhiều món) = **1 lượt `scan`** trong `useGuestQuota`.
- `MealPicker.vue` kiểm tra `canUse('scan')` trước khi gọi `detect`; hết lượt → `GuestGateModal`.
- Trừ lượt (`increment('scan')`) **chỉ khi detect thành công** (giống `Result.vue` hiện tại).
- Việc **lưu** các món sau khi pick **không** tính thêm lượt.

---

## 11. Danh sách file cần tạo / sửa

### Tạo mới
| File | Mô tả | Ưu tiên |
|------|--------|---------|
| `resources/js/composables/useFoodDetect.ts` | Gọi `/food/detect` | 🔴 P0 |
| `resources/js/pages/MealPicker.vue` | Màn chọn món + số lượng | 🔴 P0 |
| `resources/js/components/food/DishPickRow.vue` | 1 dòng món | 🔴 P0 |
| `resources/js/components/food/QuantityStepper.vue` | Stepper số lượng/khẩu phần | 🔴 P0 |
| `resources/js/utils/nutrition.ts` | Helper tính calo theo quantity | 🟡 P1 |

### Sửa
| File | Việc | Ưu tiên |
|------|------|---------|
| `app/Services/FoodAnalysisService.php` | Thêm `detectDishes()` + `normalizeDishes()` | 🔴 P0 |
| `app/Http/Controllers/Api/V1/FoodController.php` | Thêm `detect()` | 🔴 P0 |
| `routes/api_v1.php` | Thêm `POST /food/detect` | 🔴 P0 |
| `resources/js/types/food.ts` | Thêm `DetectedDish`, `DishPick`, `UnitType` | 🔴 P0 |
| `resources/js/pages/Scan.vue` | Ảnh chụp/gallery → `/meal-picker` (giữ manual/barcode → `/result`) | 🔴 P0 |
| `resources/js/router/index.ts` | Route `/meal-picker` (auth, guest OK) | 🔴 P0 |
| `resources/js/composables/useMealLog.ts` | Thêm `logMeals(batch)` | 🟡 P1 |

---

## 12. Checklist tổng

### Phase 1 — Detect + Pick + Log (P0) ✅ HOÀN THÀNH
- [x] Spec viết xong
- [x] `FoodAnalysisService::detectDishes()` + `normalizeDishes()` (Gemini JSON, ≤15 món)
- [x] `FoodController::detect()` + route `POST /food/detect` (throttle 10/min)
- [x] `types/food.ts`: `DetectedDish`, `DishPick`, `UnitType`, `DetectResponse`
- [x] `useFoodDetect.ts`
- [x] `QuantityStepper.vue` (step/min/unitLabel theo `unit_type`)
- [x] `DishPickRow.vue` (checkbox + info + stepper + calo realtime, badge confidence thấp)
- [x] `MealPicker.vue` (list, tổng động, sticky confirm, loading/empty/error)
- [x] `Scan.vue`: ảnh chụp + gallery điều hướng `/meal-picker`
- [x] Router: `/meal-picker`
- [x] Guest quota gate trong `MealPicker.vue`
- [x] `useMealLog.logMeals()` — log từng món đã chọn → `/home`
- [x] **Đã xác minh:** `StreakService::recordMealActivity` idempotent theo ngày → log nhiều món chỉ tính 1 hoạt động streak (không cộng dồn)

### Phase 2 — Polish 🟢 (phần lớn xong)
- [x] Lời khuyên AI tổng hợp cho cả bữa — `POST /food/advise-meal` (SSE) + nút "Xem nhận xét" trong MealPicker
- [x] Sửa calo/đơn vị inline (chỉnh kcal/đơn vị, có affordance bút chì) trong `DishPickRow`
- [x] Batch endpoint `POST /food/log-batch` (1 round-trip, 1 lần update streak, transaction)
- [ ] Gộp món thành 1 "bữa" (`meal_group_id`) để xem/sửa/xóa theo cụm — chưa làm
- [ ] Segmented `Ít/Vừa/Nhiều` như tùy chọn nhanh cho `portion` — chưa làm (giữ stepper 0.5)

---

## 13. Notes & quyết định mở

- **Streak khi log nhiều món:** xác minh `StreakService::recordMealActivity` idempotent theo ngày (log 5 món không = +5 hoạt động). Nếu chưa, batch endpoint P2 sẽ gọi streak đúng 1 lần.
- **Độ chính xác khẩu phần:** AI ước tính 1 khẩu phần chuẩn có sai số; confidence thấp → khuyến khích người dùng chỉnh (P2 inline edit).
- **Hiệu năng ảnh nhiều món:** giữ resize 800px như hiện tại; `detail: low` đủ để liệt kê món (không cần đọc chi tiết nhỏ).
- **Giới hạn 15 món:** tránh prompt/response quá lớn; bàn tiệc rất nhiều món hiếm gặp ở mức cá nhân.
- **Có nên thay luôn luồng 1 món?** Hiện giữ `/analyze` cho manual/barcode. Nếu muốn nhất quán hoàn toàn, có thể cho cả manual text đi qua `detect` → picker (cân nhắc P2).

---

*File này được cập nhật mỗi khi hoàn thành một task. Kiểm tra checklist trước khi bắt đầu làm.*
