# CLAUDE PROMPTS — Câu lệnh mẫu gửi Claude Code

> Copy-paste, thay `<...>` bằng chữ thầy yêu cầu. Không phải nghĩ trong lúc bảo vệ.

---

## 1. Đọc / khảo sát nhanh

```
Đọc <đường dẫn file> dòng <A>-<B>
```
Ví dụ: `Đọc app/Services/MealPlanService.php dòng 55-120`

```
Grep '<chuỗi cần tìm>' trong <thư mục>
```
Ví dụ: `Grep 'Đăng ký' trong resources/js/pages`

```
Tìm tất cả file có chữ '<X>'
```

---

## 2. Đổi text

```
Grep '<chữ cũ>' trong resources/js/pages, thay tất cả bằng '<chữ mới>'.
```

---

## 3. Đổi validation

```
Sửa validate('<trường>') trong <Controller>::<method> thành <rule mới>.
Sửa cả hàm validate() trong <Vue file> tương ứng.
```

Ví dụ:
```
Sửa validate('password') trong AuthController::register thành min:10.
Sửa cả hàm validate() trong pages/auth/Register.vue để check password.value.length >= 10.
```

---

## 4. Đổi hằng số dinh dưỡng

```
Đổi <TÊN_HẰNG> trong app/Support/NutritionStandard.php:
<key cũ> = <giá trị cũ> → <giá trị mới>.
```

Ví dụ:
```
Đổi MACRO_RATIO trong NutritionStandard.php:
protein_pct 0.15 → 0.20, carbs_pct 0.55 → 0.50 (giữ tổng = 1.0).
```

---

## 5. Đổi màu

```
Đổi bg-<class cũ> thành bg-<class mới> trong <file .vue>, chỗ <mô tả vị trí>.
```

Hoặc đổi biến CSS chung:
```
Trong resources/css/app.css đổi biến --color-calor-<tên> thành <#hex>.
```

---

## 6. Đổi giá trị món trong seed

```
Đổi <tên món> trong DishCatalogSeeder.php:
calories <cũ> → <mới>, protein <cũ> → <mới>.
Chạy: php artisan db:seed --class=DishCatalogSeeder --force.
```

---

## 7. Đổi prompt AI

```
Trong <Service>::<method>, thêm dòng vào system instruction:
"<yêu cầu mới>"
```

Ví dụ:
```
Trong FoodAnalysisService::streamAdvice thêm vào system instruction:
"LUÔN cảnh báo nếu natri > 2000mg."
```

---

## 8. Thêm trường form (4 bước)

```
Thêm trường '<tên>' (kiểu <string/int/…>) vào users:
1. Migration: nullable
2. Thêm vào $fillable trong User.php
3. AuthController::register: validate 'nullable|<rule>' và User::create
4. Register.vue step <n>: thêm ref('') + <input v-model>
Chạy migrate và verify.
```

---

## 9. Kiểm tra sau sửa

```
Chạy php artisan test --filter=<Tên>
Chạy npm run type-check
```

Xem endpoint có ổn:
```
Curl thử: curl -s -X POST http://localhost:8000/api/v1/nutrition/calculate \
  -H 'Content-Type: application/json' \
  -d '{"birth_year":1995,"gender":"male","height_cm":170,"weight_kg":65,"activity_level":"moderate","goal":"maintain"}' | python -m json.tool
```

---

## 10. Revert khi hỏng

```
git status
git diff <file>
git restore <file>              # revert 1 file, giữ các sửa khác
git reset --hard defense-<ngày>  # revert TẤT CẢ về snapshot pre-defense
```

---

## Mẹo khi ra prompt cho Claude Code

- **Nói tên feature user thấy, không phải tên biến**: "sửa nút Đăng ký" > "sửa button submitBtn".
- **Kể vị trí gần đúng**: "trong màn hình Chọn món, chỗ hiển thị calo" — Claude biết dò `MealPicker.vue` và `DishPickRow.vue`.
- **Nếu Claude sai file**: dán đường dẫn từ bảng CHEAT-SHEET.md để nó khỏi mò.
- **Nếu Claude làm quá nhiều**: dừng lại, nói "chỉ sửa 1 file X, dòng Y-Z".
- **Sau mỗi sửa**: `git status` để biết đã đụng những file nào — dễ revert.
