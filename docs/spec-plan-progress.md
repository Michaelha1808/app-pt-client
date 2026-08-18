# Spec: Áp dụng kế hoạch & Tiến độ thực hiện

> **App:** CaloEye — Laravel + Vue 3 (iOS-style PWA)
> **Cập nhật lần cuối:** 2026-08-18
> **Trạng thái tổng:** ✅ HOÀN THÀNH — verify Docker (Postgres) + Playwright UI thật

---

## Mục lục
1. [Vấn đề](#1-vấn-đề)
2. [Luồng xem trước → áp dụng](#2-luồng-xem-trước--áp-dụng)
3. [Tiến độ kế hoạch](#3-tiến-độ-kế-hoạch)
4. [API Contract](#4-api-contract)
5. [Danh sách file](#5-danh-sách-file)
6. [Checklist](#6-checklist)

---

## 1. Vấn đề

Trước thay đổi này:

- **Không có bước áp dụng.** Bấm "Tạo kế hoạch" ở `/plan` là `PlanController::generate()` `updateOrCreate` thẳng vào DB — user chưa kịp xem đã ghi đè kế hoạch cũ. Tạo lại mà không ưng thì kế hoạch cũ mất luôn.
- **Không có nơi xem tiến độ.** Trạng thái "đã xong" chỉ hiện rải rác trong thẻ Nhiệm vụ hôm nay ở Home, không có % tổng thể, không có nhìn theo tuần.
- **Không có lối vào từ Hồ sơ.**

---

## 2. Luồng xem trước → áp dụng

```
POST /plan/generate  → SSE stream plan + reasoning, LƯU NHÁP vào cache (30 phút)
                       ⚠️ KHÔNG ghi DB → kế hoạch đang dùng còn nguyên
POST /plan/apply     → đọc nháp từ cache, updateOrCreate vào meal_plans, xoá nháp
```

**Vì sao nháp nằm ở cache server, không phải client gửi plan lên:** nếu nhận `plan` JSON từ client thì nội dung lưu có thể khác bản AI đã sinh và user đã xem. Cache khoá theo `plan_draft:{user_id}:{scope}` nên user khác cũng không áp dụng nhầm nháp của nhau.

**Nháp bị tiêu thụ (`Cache::pull`)** sau khi áp dụng — bấm 2 lần không tạo 2 bản ghi.

FE giữ cờ `isDraft`: bật khi nhận event `plan` từ stream, tắt khi `fetchPlan()` hoặc `apply()` xong. Khi bật thì hiện banner "Bản xem trước" + nút "Áp dụng kế hoạch".

---

## 3. Tiến độ kế hoạch

Trang `/plan/progress`, vào từ **Hồ sơ → Tiến độ kế hoạch** hoặc nút "Xem tiến độ thực hiện" ở `/plan`.

Gồm: vòng tròn % hôm nay (đổi màu theo mức), thanh % tuần này, lời động viên theo mức hoàn thành, biểu đồ cột 7 ngày, và danh sách nhiệm vụ hôm nay kèm trạng thái.

### Cách tính "đã xong"

**Không lưu cột `done` nào trong DB** — suy ra từ `meal_logs` / `health_activities` thực tế, nên user ghi bằng cách nào (nhận diện ảnh, log nhanh, nút "Thực hiện") cũng được tính. Hai vòng khớp trong `PlanProgressService::mealTasks()`:

1. **Khớp theo tên món** — nút "Thực hiện" (`completeMeal`) chép đúng `name` từ kế hoạch sang `food_name`, nên khớp chính xác bữa user đã bấm.
2. **Khớp theo khung giờ** — cho các bữa ghi nhận theo cách khác (sáng 4–11h, trưa 11–15h, tối 17–23h, phụ 15–17h/21h+/0–4h).

⚠️ **Log đã khớp ở vòng 1 bị loại khỏi vòng 2.** Nếu không, bấm "Thực hiện" bữa sáng lúc 20h sẽ vừa tính bữa sáng (theo tên) vừa tính bữa tối (theo giờ) → một lần bấm thành 2 nhiệm vụ xong. Đây là bug thật đã gặp khi verify trên Docker.

### % tuần

Thứ 2 → hôm nay. **Ngày tương lai không tính vào %** (chưa tới thì không thể coi là chưa hoàn thành — tính vào sẽ luôn ra con số bi quan), trả `percent: null` và vẽ cột nét đứt mờ.

### Lời động viên

`PlanProgressService::encouragement()` chia mức: 100% / ≥75% / ≥50% / >0% / 0% / chưa có kế hoạch. Ở vài mức, nội dung đổi thêm theo % tuần để ngày lỡ hẹn vẫn được nhìn nhận trong bối cảnh cả tuần và không lặp một câu.

---

## 4. API Contract

### POST `/plan/apply` (auth)
```json
// Request
{ "scope": "daily" }          // daily | weekly | monthly

// 200
{ "message": "Đã áp dụng kế hoạch", "plan": {...}, "reasoning": "...",
  "target_date": "2026-08-19", "generated_at": "..." }

// 422 — nháp hết hạn (>30 phút) hoặc đã áp dụng rồi
{ "message": "Bản kế hoạch đã hết hạn. Bạn hãy tạo lại rồi áp dụng nhé." }
```

### GET `/plan/progress` (auth)
```json
{
  "today": {
    "has_plan": true,
    "meals": [{ "slot": "breakfast", "name": "...", "calories": 400, "done": true }],
    "workout": { "name": "...", "type": "cardio", "duration_min": 30, "done": false },
    "done": 1, "total": 7, "percent": 14
  },
  "week": {
    "percent": 17, "done": 2, "total": 12,
    "days": [
      { "date": "2026-08-17", "label": "T2", "percent": 40, "done": 2, "total": 5,
        "is_future": false, "is_today": false },
      { "date": "2026-08-19", "label": "T4", "percent": null, "is_future": true, "is_today": false }
    ]
  },
  "encouragement": { "emoji": "☀️", "title": "...", "message": "..." }
}
```

---

## 5. Danh sách file

**Backend:**
- [x] `app/Services/PlanProgressService.php` — mới. Phân giải nhiệm vụ theo ngày + tính %, + `encouragement()`
- [x] `app/Http/Controllers/Api/V1/PlanProgressController.php` — mới
- [x] `app/Http/Controllers/Api/V1/PlanController.php` — `generate()` chuyển sang lưu nháp; thêm `apply()`
- [x] `app/Http/Controllers/Api/V1/DailyTaskController.php` — bỏ logic tự suy luận, dùng `PlanProgressService` (giữ nguyên API ra ngoài)
- [x] `routes/api_v1.php` — `GET /plan/progress`, `POST /plan/apply`

**Frontend:**
- [x] `resources/js/composables/usePlanProgress.ts` — mới
- [x] `resources/js/composables/useMealPlan.ts` — thêm `isDraft`, `applying`, `apply()`
- [x] `resources/js/pages/PlanProgress.vue` — mới
- [x] `resources/js/pages/MealPlan.vue` — banner "Bản xem trước" + nút Áp dụng + link tiến độ
- [x] `resources/js/pages/Profile.vue` — ô lối tắt "Tiến độ kế hoạch"
- [x] `resources/js/router/index.ts` — `/plan/progress`

**Test:** `tests/Feature/PlanProgressTest.php` (7), `tests/Feature/PlanApplyTest.php` (5) — 12/12 pass.

---

## 6. Checklist

- [x] Backend + test (12/12 pass local PHP 8.5/SQLite)
- [x] Frontend + `vue-tsc` sạch + `vite build` OK
- [x] **Verify Docker (Postgres) + Playwright UI thật:**
  - Tạo kế hoạch → hiện banner "Bản xem trước" + nút Áp dụng; **server vẫn giữ plan cũ** (kiểm tra song song qua API) → bấm Áp dụng → server mới cập nhật, banner biến mất
  - Hồ sơ → lối tắt → trang tiến độ render đúng (vòng %, thanh tuần, 7 cột, lời động viên, danh sách nhiệm vụ)
  - `daily-tasks` + `complete-meal` vẫn chạy đúng sau refactor, nhất quán với trang tiến độ

**Bug phát hiện & sửa khi verify thật:**
1. Bấm "Thực hiện" bữa sáng lúc 20h → đánh dấu nhầm **bữa tối** xong (chỉ suy theo khung giờ) → thêm khớp theo tên món.
2. Sau khi thêm khớp tên, một lần bấm bị tính **2 nhiệm vụ** (cả tên lẫn giờ) → loại log đã khớp tên khỏi vòng khớp theo giờ.
3. Cột biểu đồ tuần dùng token `--color-matcha-light`, ở theme "green" token này map sang **xanh dương** (`#5AC8FA`) → lệch tông; đổi sang `bg-calor-green/40`.
4. `updateOrCreate` với `target_date` dạng chuỗi không khớp bản ghi sẵn có trên mọi driver → truyền `Carbon` (cũng sửa cho `ChatController::applyPlan`).

**Ngoài phạm vi:** cho phép sửa tay từng mục trong kế hoạch trước khi áp dụng; tiến độ theo tháng.
