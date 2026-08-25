# SAFETY CHECKLIST — Chạy trước bảo vệ

> Tick từng dòng trước khi bảo vệ 1–2 tiếng.

---

## Trước bảo vệ

- [ ] **Tạo git tag** để có snapshot revert:
      ```
      git tag defense-YYYY-MM-DD
      git tag | grep defense
      ```
- [ ] **Backup DB** (nếu chạy SQLite local):
      ```
      cp database/database.sqlite database/database.sqlite.backup-defense
      ```
- [ ] **Backup .env**:
      ```
      cp .env .env.backup-defense
      ```
- [ ] **Kéo code mới nhất** trên `main` (không phải nhánh feature dở):
      ```
      git switch main
      git status                    # phải sạch
      git log --oneline -5           # kiểm tra commit mới nhất
      ```
- [ ] **Chạy migrate + seed** để đảm bảo DB đủ data demo:
      ```
      php artisan migrate --force
      php artisan db:seed --class=DishCatalogSeeder --force
      ```
- [ ] **Test suite pass**:
      ```
      php artisan test
      ```
      Nếu có test fail thì phải hiểu lý do (đa số fail hiện tại do Gemini test env — không phải bug thật).
- [ ] **Type check FE pass**:
      ```
      npm run type-check
      ```

## Setup terminal khi bảo vệ

- [ ] **Terminal 1**: `composer run dev` — chạy suốt bảo vệ. FE hot reload; backend đổi thì refresh browser là thấy.
- [ ] **Terminal 2**: Claude Code — mở sẵn tại `d:\laragon\www\app-pt-client`.
- [ ] **Terminal 3**: trống — dùng cho `git`, `php artisan`, revert.
- [ ] **Browser**: mở sẵn `http://localhost:8000`.
- [ ] **Tab thứ 2**: `http://localhost:8000/admin` (nếu có admin login).
- [ ] **Tab thứ 3**: mở sẵn `docs/defense/00-CHEAT-SHEET.md` trong VS Code / editor để tra nhanh.

## Trong lúc bảo vệ

- [ ] Sau mỗi sửa: **`git status`** để biết đụng file nào.
- [ ] Sau mỗi sửa quan trọng: **`git diff`** để thầy xem thay đổi (nếu thầy đòi).
- [ ] Đừng chạy `git commit` giữa bảo vệ — chỉ commit sau khi mọi thứ xong.
- [ ] **Không** chạy `git push`, `migrate:fresh`, `db:seed --class=DatabaseSeeder`, `git reset --hard origin/main` trừ khi thầy yêu cầu rõ.

## Sau bảo vệ

- [ ] Nếu cần giữ những gì đã sửa lúc bảo vệ:
      ```
      git checkout -b defense-changes-<ngày>
      git add -A
      git commit -m "Sửa trong lúc bảo vệ: <mô tả>"
      ```
- [ ] Nếu muốn bỏ hết:
      ```
      git reset --hard defense-<ngày>
      cp database/database.sqlite.backup-defense database/database.sqlite
      ```

---

## Rehearsal — làm thử 4 tình huống, canh giờ

**Mục tiêu: mỗi tình huống < 60 giây**.

1. **"Đổi màu nút Đăng nhập thành cam"**
   - Mở `resources/js/pages/auth/Login.vue`
   - Tìm class `bg-calor-green` ở nút submit → đổi `bg-orange-500`
   - Save → browser tự refresh → verify

2. **"Bắt buộc user chọn giới tính, bỏ 'Khác'"**
   - Backend: `AuthController::register` — đổi `'gender' => 'required|in:male,female,other'` thành `'required|in:male,female'`
   - Frontend: `Register.vue` — mảng `genders` bỏ `{ value: 'other', ... }`
   - Verify: chọn "Khác" không còn hiện

3. **"Đổi tỉ lệ macro protein từ 15% lên 20%"**
   - `app/Support/NutritionStandard.php` — `MACRO_RATIO`:
     - `protein_pct: 0.15 → 0.20`
     - `carbs_pct: 0.55 → 0.50` (giữ tổng = 1.0)
   - Verify: gọi `/nutrition/calculate` bằng curl (xem `02-CLAUDE-PROMPTS.md` #9), kiểm số P tăng.

4. **"Phở bò 450 kcal quá thấp, đổi thành 550"**
   - `database/seeders/DishCatalogSeeder.php` — dòng `'Phở bò'` đổi `450 → 550`
   - Chạy `php artisan db:seed --class=DishCatalogSeeder --force`
   - Verify: `php artisan tinker` → `\App\Models\Dish::where('name_normalized','pho bo')->first()->calories` → phải là 550.

Sau khi rehearse xong: `git restore .` để bỏ hết sửa thử.

---

## Câu trả lời cho câu thầy hay hỏi

- **"Số calo lấy đâu?"** → *"Trước đây AI đoán, giờ có grounding vào bảng `dishes` chuẩn ở backend, chỉ dùng AI khi món không khớp. Xem `DishCatalogService::groundOne`."*
- **"Công thức TDEE sao lại vậy?"** → *"BMR Mifflin-St Jeor 1990, PAL theo WHO/FAO 2001, cả 2 đều được VDD 2016 công nhận. Xem `NutritionStandard::bmr` và `PAL`, citations trong `citations()`."*
- **"Có cơ sở gì không?"** → *"Bảng Nhu cầu Dinh dưỡng Khuyến nghị VDD 2016 (Bộ Y tế), WHO/FAO Energy Requirements 2001, Mifflin-St Jeor 1990 — bấm vào 'Cơ sở tham chiếu' dưới mỗi tư vấn ở Result / MealPicker / MealPlan / Register là ra."*
- **"Sao AI trả kết quả khác nhau mỗi lần?"** → *"Gemini là generative model, có ngẫu nhiên (temperature). Đây là lý do có grounding vào DB chuẩn — số cố định cho món phổ biến, AI chỉ đoán món ngoài catalog."*
- **"Nếu AI trả sai/độc?"** → *"Có `NutritionValidator` sanity-check macro/kcal theo Atwater (P×4 + C×4 + F×9); lệch > 20% sẽ hiển thị cảnh báo cho user."*
- **"Rate limit thế nào?"** → *"Admin cấu hình runtime qua `/admin/settings` — `rate_limit.food_analyze_per_min`, `rate_limit.chat_per_min`, `rate_limit.plan_generate_per_min`."*
