# COMMON ASKS — Playbook 8 dạng yêu cầu thầy hay hỏi

> Mỗi dạng có: cách nhận diện → file → prompt sẵn cho Claude Code.
> Mục tiêu: **dưới 60 giây/yêu cầu**.

---

## A. Đổi text hiển thị

**Thầy nói**: *"Đổi 'Đăng ký' thành 'Tạo tài khoản'"*, *"Sửa tiêu đề trang này"*, *"Text này khó hiểu, đổi đi"*.

**File đích**: `resources/js/pages/**/*.vue` (chủ yếu), đôi khi `app/Http/Controllers/*.php` (message trả về).

**Prompt Claude Code**:
```
Grep chữ 'Đăng ký' trong resources/js/pages, thay hết bằng 'Tạo tài khoản'.
```

Nếu chuỗi có nhiều nơi (button, aria-label, title...): grep trước xem có bao nhiêu match rồi mới đổi.

---

## B. Đổi ràng buộc validation

**Thầy nói**: *"Password phải tối thiểu 10 ký tự"*, *"Cho đăng ký từ 15 tuổi"*, *"Bắt buộc phải nhập số điện thoại"*.

**File đích**:
- **Backend**: `app/Http/Controllers/Api/V1/<Something>Controller.php` — tìm `$request->validate([...])`.
- **Frontend**: `resources/js/pages/*.vue` — tìm hàm `validate()` hoặc regex như `emailRe`, hằng số `min:`.

**⚠ Sửa cả 2 phía** nếu không muốn thầy hỏi "sao FE vẫn cho nhập?"

**Prompt Claude Code**:
```
Sửa validate('password') trong AuthController::register thành min:10.
Sửa cả validate() bên pages/auth/Register.vue.
```

---

## C. Đổi màu / kích thước / layout

**Thầy nói**: *"Đổi màu nút thành cam"*, *"Nút hơi nhỏ, làm to lên"*, *"Chữ này cần bold"*.

**File đích**:
- Nút/component cụ thể: Tailwind classes trong template `.vue` — thường ở `bg-calor-green`, `h-[50px]`, `text-[16px]`, `font-semibold`.
- Toàn app: `resources/css/app.css` — biến `--color-calor-green`, `--color-calor-mint`, `--color-calor-deep`...

**Prompt Claude Code**:
```
Đổi bg-calor-green thành bg-orange-500 trong pages/auth/Login.vue nút Đăng nhập.
```

Hoặc đổi global:
```
Trong resources/css/app.css đổi --color-calor-green sang màu cam #FF6B00.
```

**Lưu ý**: Tailwind v4 (dự án đang dùng) đọc màu trực tiếp từ CSS variable — không có `tailwind.config.js` truyền thống.

---

## D. Đổi giới hạn số / preset

**Thầy nói**: *"Đổi mục tiêu calo tối thiểu từ 1200 → 1000"*, *"Đổi phở bò từ 450 → 500 kcal"*, *"Đổi tỉ lệ protein từ 15% lên 20%"*.

**File đích** (tra bảng 2 của CHEAT-SHEET):
- Hằng số dinh dưỡng: `app/Support/NutritionStandard.php`
- Số món chuẩn: `database/seeders/DishCatalogSeeder.php` (sửa xong chạy `php artisan db:seed --class=DishCatalogSeeder --force`)
- Ngưỡng fuzzy match: `app/Services/DishCatalogService.php` `FUZZY_THRESHOLD`

**Prompt Claude Code**:
```
Trong NutritionStandard.php đổi MACRO_RATIO protein 0.15 → 0.20,
carbs 0.55 → 0.50 (giữ tổng 100%).
```

**Sau khi sửa seeder**: chạy `php artisan db:seed --class=DishCatalogSeeder --force` — dữ liệu chuẩn cập nhật ngay.

---

## E. Thêm trường mới vào form  ⚠ NẶNG NHẤT

**Thầy nói**: *"Thêm số điện thoại"*, *"Thêm nghề nghiệp"*, *"Thêm câu hỏi có bệnh nền không"*.

**File đích** (4 bước, KHÔNG bỏ bước):
1. **Migration**: tạo file `database/migrations/2026_XX_XX_add_<field>_to_users_table.php` với `$table->string('<field>')->nullable()`.
2. **Model**: thêm `'<field>'` vào `#[Fillable]` array trong `app/Models/User.php`.
3. **Controller**: thêm rule vào `AuthController::register` validate + `User::create([...])`.
4. **Vue**: thêm `ref('')` + `<input v-model="...">` trong `resources/js/pages/auth/Register.vue`.

**Chạy sau khi sửa**:
```
php artisan migrate
```

**Prompt Claude Code**:
```
Thêm trường 'phone' (nullable string) vào users:
1. Migration mới
2. User $fillable
3. AuthController::register validate nullable|string|max:20 và Create
4. Register.vue step 2 thêm input SĐT
Chạy migrate xong verify.
```

**Nếu hết giờ**: xin thầy cho làm 1 nửa (chỉ FE, chưa gắn DB) — vẫn thấy được UI.

---

## F. Đổi công thức tính

**Thầy nói**: *"Công thức TDEE sao lại nhân 1.375?"*, *"Chỉ số streak tính thế nào?"*, *"Đổi công thức giảm cân"*.

**File đích**:
- Nutrition (BMR/TDEE/macros/nước): `app/Support/NutritionStandard.php`
- Streak: `app/Services/StreakService.php`
- Weight goal auto-adjust: `app/Services/WeightService.php` method `suggestGoal`
- Sanity check macro/kcal (Atwater): `app/Support/NutritionValidator.php`

**Prompt Claude Code**:
```
Đổi công thức tdee() trong NutritionStandard.php:
BMR * PAL, cộng thêm hệ số 200 kcal cho nam trên 30 tuổi.
```

**Nhớ trích nguồn**: nếu thầy hỏi "cơ sở đâu?" thì `NutritionStandard::citations()` đã có sẵn 3 nguồn (VDD 2016, WHO/FAO 2001, Mifflin 1990). Bạn dẫn ra được.

---

## G. Đổi prompt AI

**Thầy nói**: *"AI trả lời sai quá, sửa prompt đi"*, *"Bắt AI nói cảnh báo natri"*, *"AI dùng markdown, cấm đi"*.

**File đích**: heredoc `PROMPT` hoặc `systemInstruction` trong:
- `app/Services/FoodAnalysisService.php` — `getStructuredData`, `detectDishes`, `streamAdvice`, `streamMealAdvice`, `estimateNutrition`
- `app/Services/MealPlanService.php` — `dailyPrompt`, `weeklyPrompt`, `monthlyPrompt` + systemInstruction
- `app/Services/ChatService.php` — `buildUserContext`, systemInstruction trong `stream`
- Grounding tham chiếu VDD: `app/Support/NutritionStandard.php` `promptStandardsBlock()`

**Prompt Claude Code**:
```
Trong FoodAnalysisService::streamAdvice, thêm dòng vào system instruction:
"LUÔN cảnh báo nếu natri > 2000mg."
```

**Không cần restart** — prompt sửa xong, request tiếp theo AI dùng luôn.

---

## H. Bật/tắt tính năng

**Thầy nói**: *"Tắt Google login đi"*, *"Tắt AI phân tích"*, *"Cấm khách không đăng nhập dùng"*.

**KHÔNG SỬA CODE** — dùng cách runtime:

**Option 1 (nhanh nhất)**: Đăng nhập admin → `/admin/settings` → toggle:
- `oauth.google_enabled`
- `oauth.facebook_enabled`
- `features.guest_mode_enabled`
- `features.registration_open`
- `ai.food_analysis_enabled`
- `ai.chat_enabled`

**Option 2 (nếu không có tài khoản admin)**: sửa default trong `app/Http/Controllers/Api/V1/AppConfigController.php` — thay `true` thành `false`.

**Prompt Claude Code**:
```
Trong AppConfigController::index đổi 'google_enabled' default sang false.
```

---

## Cấp cứu — Khi mọi thứ hỏng

**FE trắng đen sau khi sửa**:
```
git status         # xem đã sửa gì
git restore <file> # revert 1 file
```

**Backend 500 error**:
```
php artisan config:clear
php artisan cache:clear
```
Đọc log: `storage/logs/laravel.log` (mở file bằng Read tool).

**DB hỏng (SQLite)**:
```
cp database/database.sqlite.backup-defense database/database.sqlite
```
(File backup đã tạo trước khi bảo vệ — xem `03-SAFETY.md`)

**Về nguyên trạng hoàn toàn**:
```
git reset --hard defense-<ngày bảo vệ>
```
(Tag đã tạo trước khi bảo vệ.)
