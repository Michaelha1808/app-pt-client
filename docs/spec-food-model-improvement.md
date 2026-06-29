# Spec: Cải thiện độ chính xác nhận diện món ăn (Food Model Improvement)

> **App:** CaloEye — Vue 3 SPA + Laravel 13 (provider AI thực tế: Gemini)
> **Nhánh:** `develop-model`
> **Cập nhật:** 2026-06-28
> **Trạng thái tổng:** 🟢 Bước 1–3 đã implement & verify trên Docker (PHP 8.4) · 🟡 Bước 4 có script export, chờ dataset + GCP

---

## 1. Bối cảnh & vấn đề

Nhận diện món ăn hiện **thuần prompt + Gemini JSON mode** ([`FoodAnalysisService`](../app/Services/FoodAnalysisService.php)), **không có nutrition DB** → calo/macro do Gemini **đoán 100%**. Hai pain chính:

1. **Nhận sai tên món** (phở vs bún bò, miến vs phở…).
2. **Calo/macro sai số** (đoán mò, thiếu nguồn chuẩn).

**Sự thật nền tảng:** Gemini qua API là model đóng → **không "train" được trọng số qua API thường**. "Training" thật chỉ có ở Vertex AI (fine-tune) và là bước cuối. Cú hích chính xác lớn nhất đến từ **grounding (nutrition DB) + feedback loop**, không phải fine-tune.

## 2. Lộ trình (ROI giảm dần)

| Bước | Nội dung | Chữa pain | Cần Python? | Trạng thái |
|------|----------|-----------|-------------|------------|
| **1** | **Thu dataset sửa lỗi** (AI đoán vs user chốt + ảnh) | nền cho mọi bước | Không | 🟢 Done |
| **2** | Nutrition DB món Việt + grounding calo | calo sai + tên sai | Không | 🟢 Done (34 món seed) |
| **3** | Prompt nhận biết thư viện + few-shot động từ dataset | tên sai | Một phần (curate) | 🟢 Done |
| **4** | Fine-tune Gemini Flash qua **Vertex AI** | tên sai (nếu còn) | **Có** (offline) | 🟡 Script export sẵn (`ml/`), chờ dataset + GCP |

> **Python nằm ở pipeline OFFLINE** (curate dataset, embeddings, fine-tune Vertex AI) — KHÔNG dựng Python microservice chạy inference ở production. Inference giữ ở Laravel.

---

## 3. Bước 1 — Thu dataset sửa lỗi (đã implement)

### 3.1 Ý tưởng
Mỗi lần `detect` → lưu 1 bản ghi `food_detection_samples` chứa **AI đoán** (`ai_dishes`) + **ảnh** (downscale, disk private). Khi user xác nhận/chỉnh ở `MealPicker` → cập nhật **user chốt** (`corrected_dishes`) + cờ `has_correction`, `saved`. Đây là "training data" cho bước 3–4.

### 3.2 Quyết định thiết kế
- **Lưu ảnh:** có, downscale ~512px (best-effort khi có GD; FE đã resize 800px nên không có GD vẫn chấp nhận được). Disk **private** `storage/app/private/food-samples/Y/m/uuid.jpg`.
- **Sửa tên món:** thêm UI inline trong `DishPickRow` (bút chì cạnh tên) → thu được tín hiệu **sai tên** (pain #1) vốn trước đây không bắt được.
- **Điểm bắt:** ghi `ai_dishes` ngay tại `detect` (server đã có sẵn); ghi `corrected_dishes` khi user **xác nhận** (saved=true) hoặc **rời màn** (saved=false, best-effort). Ghi 1 lần, không ghi đè.
- **Guest:** vẫn thu (user_id null).
- **An toàn:** mọi thao tác thu thập là best-effort — lỗi không được làm hỏng luồng nhận diện chính.

### 3.3 DB — `food_detection_samples`
| Cột | Kiểu | Ghi chú |
|-----|------|---------|
| `user_id` | FK nullable | guest = null, set null khi xoá user |
| `input_type` | string(10) | `image` \| `text` |
| `image_path` | string nullable | path disk private |
| `text_input` | text nullable | nếu nhập mô tả thay ảnh |
| `model` | string nullable | model Gemini sinh `ai_dishes` |
| `ai_dishes` | json | AI đoán (đã normalize) |
| `corrected_dishes` | json nullable | user chốt: `{food_name, calories, quantity, selected}[]` |
| `has_correction` | bool | có khác biệt AI vs user |
| `saved` | bool | user có ghi nhật ký không |

`has_correction` = true nếu user **bỏ chọn** / **đổi tên** / **đổi calo** / **đổi số lượng** so với `ai_dishes`.

### 3.4 API
- `POST /food/detect` → response thêm `detection_id`.
- `POST /food/detect/{sample}/feedback` (public, throttle 20/min)
  ```json
  { "saved": true,
    "dishes": [ { "food_name": "...", "calories": 200, "quantity": 1.5, "selected": true } ] }
  ```
  `dishes` gửi **tất cả** món (kể cả bỏ chọn) theo đúng thứ tự `ai_dishes`. `calories` là calo/1 đơn vị.

### 3.5 File đã tạo / sửa
**Tạo:**
- `database/migrations/2026_06_28_000020_create_food_detection_samples_table.php`
- `app/Models/FoodDetectionSample.php`
- `app/Services/FoodSampleService.php` (capture + recordFeedback + lưu/downscale ảnh)

**Sửa:**
- `app/Services/FoodAnalysisService.php` — thêm `modelName()`
- `app/Http/Controllers/Api/V1/FoodController.php` — `detect()` capture + trả `detection_id`; thêm `detectFeedback()`
- `routes/api_v1.php` — route feedback
- `resources/js/types/food.ts` — `DetectResponse.detection_id`
- `resources/js/composables/useFoodDetect.ts` — expose `detectionId`
- `resources/js/components/food/DishPickRow.vue` — UI sửa tên món + emit `update:food_name`
- `resources/js/pages/MealPicker.vue` — `detectionId`, handler sửa tên, `sendDetectFeedback()`

### 3.6 Checklist Bước 1
- [x] Migration + model `FoodDetectionSample`
- [x] `FoodSampleService` (capture, recordFeedback, lưu ảnh downscale private)
- [x] `detect()` trả `detection_id` + capture
- [x] Endpoint `POST /food/detect/{sample}/feedback`
- [x] UI sửa tên món trong `DishPickRow`
- [x] `MealPicker` gửi feedback khi xác nhận / rời màn
- [x] PHP `-l` pass · `vue-tsc --noEmit` pass
- [ ] **Chạy migrate** (cần PHP ≥8.4.1; máy dev hiện chỉ có 8.3) — chạy ở môi trường chuẩn
- [ ] Trang admin xem/duyệt/export dataset (đề xuất bước phụ trước khi sang Bước 2)

---

## 4. Bước 2 — Nutrition DB + grounding (đã implement)

- Bảng `dishes` (tên canonical + `name_normalized` không dấu + `aliases` + unit + calo/macro cho 1 đơn vị chuẩn). Seed `DishCatalogSeeder` (34 món Việt phổ biến, idempotent).
- `DishCatalogService`: `normalize()` bỏ dấu tiếng Việt + đ→d; `match()` khớp exact → alias → fuzzy (`similar_text` ≥ 88%); `ground()` thay tên/calo/macro/serving bằng giá trị DB, nâng confidence ≥ 0.9, set `source='catalog'` + `dish_id`. Món không khớp → `source='ai'` (ứng viên bổ sung thư viện).
- `FoodController::detect`: **capture AI raw TRƯỚC** (dataset trung thực) → **ground SAU** (response cho user). Frontend hiện badge `📚 Thư viện` khi `source==='catalog'`.
- Command `php artisan dish:match "<tên>"` để debug/ops khớp tên.
- **File:** `dishes` migration + `app/Models/Dish.php` + `app/Services/DishCatalogService.php` + `database/seeders/DishCatalogSeeder.php` + `app/Console/Commands/DishMatch.php`; sửa `FoodController`, `types/food.ts` (`source`, `dish_id`), `DishPickRow.vue` (badge).
- **Verify:** `dish:match` khớp đúng "pho bo", "Phở Bò Tái", "com tam suon", "nem ran"(alias), "banh my thit"(typo), từ chối món lạ. Detect text thật (Gemini 2.5-flash) → 3 món đều grounding vào thư viện, calo từ DB; sample lưu AI raw.

## 5. Bước 3 — Prompt nhận biết thư viện + few-shot động (đã implement)

- `DishCatalogService::names()` → danh sách tên chuẩn nhồi vào prompt detect ("ưu tiên dùng đúng tên trong danh sách") → tăng tỉ lệ grounding khớp.
- `FoodSampleService::nameCorrectionExamples()` → rút cặp "AI đoán sai → user sửa đúng" (distinct, từ ≤50 mẫu mới nhất có sửa) nhồi prompt làm few-shot tránh lặp lỗi. Rỗng khi dataset chưa có dữ liệu → vô hại.
- `FoodAnalysisService::detectDishes()` nhận thêm `$catalogNames`, `$corrections`; `buildNameHint()` dựng đoạn gợi ý. Controller truyền vào.

## 6. Bước 4 — Pipeline Python fine-tune Vertex AI (script sẵn, chờ dữ liệu)

- Thư mục `ml/`: `export_dataset.py` (đọc Postgres → `out/dataset.jsonl` + copy ảnh; dựng "đáp án vàng" bằng cách áp sửa của user lên `ai_dishes`), `requirements.txt`, `README.md` (hướng dẫn convert sang định dạng Vertex + upload GCS + tạo tuning job).
- `ml/out/` đã thêm `.gitignore` (ảnh người dùng — riêng tư).
- **Chưa chạy:** Python chưa cài trên máy dev + dataset hiện trống. Chỉ fine-tune khi có ≥ vài trăm ví dụ có sửa và Bước 2–3 đã hết dư địa.

## 7. Admin — Quản lý thư viện + duyệt dataset (đã implement)

- **Thư viện món** (`/admin/dishes`): CRUD đầy đủ. `DishController` (index search + normalize, store/update tự tính `name_normalized`, destroy) + audit log `dish.create|update|delete`. UI: bảng + modal thêm/sửa (tên, biệt danh, đơn vị, calo/macro).
- **Dataset AI** (`/admin/dataset`): read-only + xoá. `DatasetController` (stats, index lọc `only_corrections`/`input_type`, show kèm ảnh base64, destroy xoá cả file ảnh). UI: 4 thẻ thống kê + bảng + modal so sánh **AI đoán vs user chốt** (đánh dấu đổi tên / bỏ chọn) + xem ảnh.
- **File:** `app/Http/Controllers/Api/V1/Admin/{DishController,DatasetController}.php`; routes admin; `types/admin.ts` (DishRow/Input, Dataset*); `useAdmin.ts` (8 method); `pages/admin/{Dishes,Dataset}.vue`; router + `AdminLayout.vue` nav.
- **Verify:** tạo món qua admin → `dish:match` khớp ngay (CRUD nuôi thẳng grounding); audit log ghi đúng; stats/list/search hoạt động.

## 8. Việc còn lại (đề xuất)

- 🔴 Mở rộng seed `dishes` từ bảng thành phần thực phẩm VN (hiện mới 34 món).
- 🔴 Nút "Thêm vào thư viện" ngay trong modal dataset (one-click tạo `dish` từ mẫu `source='ai'` hay gặp).
- 🔴 Khi `portion` từ catalog ghi đè `unit_type` countable của AI: đã quy `quantity_default=1`, nhưng nên kiểm UX stepper cho khớp.

---

*Cập nhật checklist khi hoàn thành từng task.*
