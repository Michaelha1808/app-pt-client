# Spec: Quản lý thông tin cá nhân (Profile Management)

> **App:** CaloEye — Nuxt 3 + Vue 3 + Tailwind CSS 4 (iOS-style PWA)  
> **Cập nhật lần cuối:** 2026-06-19  
> **Trạng thái tổng:** ✅ TẤT CẢ PHASE HOÀN THÀNH — Sẵn sàng test

---

## Mục lục
1. [Tổng quan kiến trúc](#1-tổng-quan-kiến-trúc)
2. [Thay đổi Database (Backend)](#2-thay-đổi-database-backend)
3. [API Contract (Backend)](#3-api-contract-backend)
4. [Backend — Model & Controller](#4-backend--model--controller)
5. [Frontend — Type `User` cập nhật](#5-frontend--type-user-cập-nhật)
6. [Frontend — Composable `useProfile`](#6-frontend--composable-useprofile)
7. [Màn hình Profile `/profile`](#7-màn-hình-profile-profile)
8. [Màn hình Chỉnh sửa hồ sơ `/profile/edit`](#8-màn-hình-chỉnh-sửa-hồ-sơ-profileedit)
9. [Chức năng thay đổi ảnh đại diện](#9-chức-năng-thay-đổi-ảnh-đại-diện)
10. [Danh sách file cần tạo / sửa](#10-danh-sách-file-cần-tạo--sửa)
11. [Checklist tổng](#11-checklist-tổng)

---

## 1. Tổng quan kiến trúc

```
[Nuxt Frontend]                    [Backend API :8000/api/v1]
     │                                       │
     │── GET  /user/profile ────────────────▶ │ → { user } (full profile với các trường mới)
     │── PATCH /user/profile ───────────────▶ │ → { user } (cập nhật thông tin)
     │── POST  /user/avatar ────────────────▶ │ → { avatar_url } (upload ảnh đại diện)
     │── DELETE /user/avatar ───────────────▶ │ → 204 (xoá ảnh đại diện về default)
```

**Chiến lược avatar:**
- Upload qua `multipart/form-data` lên backend
- Backend lưu file vào `storage/app/public/avatars/` và trả về URL public
- URL dạng `{APP_URL}/storage/avatars/{filename}`
- Max size: 5MB, chỉ cho phép `image/jpeg`, `image/png`, `image/webp`
- Client resize ảnh xuống max 512×512 trước khi upload (dùng Canvas API)

**Chiến lược state:**
- Sau khi update profile → gọi lại `/auth/me` để sync `auth.user` state
- `useProfile` composable wrap các API call, expose `loading`, `error`, `save()`, `uploadAvatar()`

---

## 2. Thay đổi Database (Backend)

### 2.1 Migration mới

**File:** `backend/database/migrations/2026_06_19_000000_add_profile_fields_to_users_table.php`

Thêm vào bảng `users`:

| Cột | Kiểu | Nullable | Default | Ghi chú |
|-----|------|----------|---------|---------|
| `avatar_url` | `string(500)` | ✅ | null | URL ảnh đại diện |
| `birth_year` | `smallInteger` | ✅ | null | Năm sinh, 1900–2015 |
| `gender` | `enum('male','female','other')` | ✅ | null | Giới tính |
| `height_cm` | `decimal(5,1)` | ✅ | null | Chiều cao cm |
| `weight_kg` | `decimal(5,1)` | ✅ | null | Cân nặng kg |
| `calorie_goal` | `smallInteger` | ✅ | 2000 | Mục tiêu calo/ngày |
| `morning_notify` | `time` | ✅ | '07:00:00' | Giờ nhắc buổi sáng |
| `evening_notify` | `time` | ✅ | '21:00:00' | Giờ nhắc buổi tối |
| `calorie_streak` | `smallInteger` | ❌ | 0 | Số ngày streak liên tiếp |

> **Lưu ý:** `birth_year`, `gender`, `height_cm`, `weight_kg` đã có trong payload `/auth/register` nhưng **chưa được lưu vào DB**. Migration này bổ sung các cột để fix điều đó. Sau khi migrate cần update `AuthController::register()` để lưu các trường này.

### 2.2 Rollback

```php
// down():
$table->dropColumn(['avatar_url','birth_year','gender','height_cm','weight_kg',
                    'calorie_goal','morning_notify','evening_notify','calorie_streak']);
```

---

## 3. API Contract (Backend)

### 3.1 GET `/user/profile`

- **Auth:** Bearer token bắt buộc
- **Response 200:**
```json
{
  "user": {
    "id": "1",
    "email": "user@example.com",
    "name": "Nguyễn Văn A",
    "avatar_url": "http://localhost:8000/storage/avatars/uuid.jpg",
    "provider": "email",
    "birth_year": 1998,
    "gender": "male",
    "height_cm": 170.0,
    "weight_kg": 65.5,
    "calorie_goal": 2000,
    "morning_notify": "07:00",
    "evening_notify": "21:00",
    "calorie_streak": 0
  }
}
```

### 3.2 PATCH `/user/profile`

- **Auth:** Bearer token bắt buộc
- **Content-Type:** `application/json`
- **Request (tất cả optional, chỉ gửi trường cần cập nhật):**
```json
{
  "name": "Nguyễn Văn A",
  "birth_year": 1998,
  "gender": "male",
  "height_cm": 170.0,
  "weight_kg": 65.5,
  "calorie_goal": 2000,
  "morning_notify": "07:00",
  "evening_notify": "21:00"
}
```

- **Response 200:** `{ "user": { ...full profile... } }`
- **Response 422:**
```json
{ "detail": "Tên phải có ít nhất 2 ký tự" }
```

**Validation rules:**
```
name:           optional | min:2 | max:100
birth_year:     optional | integer | between:1900,2015
gender:         optional | in:male,female,other
height_cm:      optional | numeric | between:50,300
weight_kg:      optional | numeric | between:20,500
calorie_goal:   optional | integer | between:1000,5000
morning_notify: optional | date_format:H:i
evening_notify: optional | date_format:H:i
```

### 3.3 POST `/user/avatar`

- **Auth:** Bearer token bắt buộc
- **Content-Type:** `multipart/form-data`
- **Request:**
```
avatar: File (jpg/png/webp, max 5MB)
```

- **Response 200:**
```json
{ "avatar_url": "http://localhost:8000/storage/avatars/uuid.webp" }
```

- **Response 422:**
```json
{ "detail": "File phải là ảnh (jpg, png, webp) và không vượt quá 5MB" }
```

### 3.4 DELETE `/user/avatar`

- **Auth:** Bearer token bắt buộc
- **Response 204:** No Content — backend xoá file cũ, set `avatar_url = null`

---

## 4. Backend — Model & Controller

### 4.1 Cập nhật `User` model

**File:** `backend/app/Models/User.php`

```php
// Thêm vào #[Fillable]:
'avatar_url', 'birth_year', 'gender', 'height_cm', 'weight_kg',
'calorie_goal', 'morning_notify', 'evening_notify', 'calorie_streak'

// Thêm vào casts():
'birth_year'    => 'integer',
'height_cm'     => 'decimal:1',
'weight_kg'     => 'decimal:1',
'calorie_goal'  => 'integer',
'calorie_streak'=> 'integer',
```

### 4.2 Tạo `UserController`

**File:** `backend/app/Http/Controllers/Api/V1/UserController.php`

```php
// Methods:
public function profile(Request $request)      // GET /user/profile
public function updateProfile(Request $request) // PATCH /user/profile
public function uploadAvatar(Request $request)  // POST /user/avatar
public function deleteAvatar(Request $request)  // DELETE /user/avatar

private function formatUser($user): array       // gọi lại từ AuthController (refactor chung)
```

### 4.3 Cập nhật `AuthController`

- `formatUser()` cần trả thêm các trường mới: `birth_year`, `gender`, `height_cm`, `weight_kg`, `calorie_goal`, `morning_notify`, `evening_notify`, `calorie_streak`
- `register()` cần lưu `birth_year`, `gender`, `height_cm`, `weight_kg`, `calorie_goal` khi tạo user

**Tốt nhất:** extract `formatUser()` thành trait hoặc helper dùng chung cho cả hai controller.

### 4.4 Routes

**File:** `backend/routes/api.php` — thêm vào route group `auth:sanctum`:

```php
Route::middleware('auth:sanctum')->group(function () {
    // ...routes hiện tại...
    Route::get('/user/profile', [UserController::class, 'profile']);
    Route::patch('/user/profile', [UserController::class, 'updateProfile']);
    Route::post('/user/avatar', [UserController::class, 'uploadAvatar']);
    Route::delete('/user/avatar', [UserController::class, 'deleteAvatar']);
});
```

---

## 5. Frontend — Type `User` cập nhật

**File:** `frontend/src/types/auth.ts`

Thêm các trường mới vào interface `User`:

```typescript
export interface User {
  id: string
  email: string
  name: string
  avatar_url: string | null
  provider: 'email' | 'google' | 'apple'
  // Các trường profile mới:
  birth_year: number | null
  gender: 'male' | 'female' | 'other' | null
  height_cm: number | null
  weight_kg: number | null
  calorie_goal: number | null
  morning_notify: string | null  // "HH:mm"
  evening_notify: string | null  // "HH:mm"
  calorie_streak: number
}

export interface UpdateProfilePayload {
  name?: string
  birth_year?: number
  gender?: 'male' | 'female' | 'other'
  height_cm?: number
  weight_kg?: number
  calorie_goal?: number
  morning_notify?: string
  evening_notify?: string
}
```

---

## 6. Frontend — Composable `useProfile`

**File:** `frontend/src/composables/useProfile.ts`

```typescript
export function useProfile() {
  const { user, extractError } = useAuth()

  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')

  // Computed helpers từ user state
  const age = computed(() => {
    if (!user.value?.birth_year) return null
    return new Date().getFullYear() - user.value.birth_year
  })

  const bmi = computed(() => {
    const h = user.value?.height_cm, w = user.value?.weight_kg
    if (!h || !w) return null
    return (w / ((h / 100) ** 2)).toFixed(1)
  })

  const bmr = computed(() => {
    const { height_cm, weight_kg, birth_year, gender } = user.value ?? {}
    if (!height_cm || !weight_kg || !birth_year) return null
    const a = new Date().getFullYear() - birth_year
    // Mifflin-St Jeor
    const base = 10 * weight_kg + 6.25 * height_cm - 5 * a
    return Math.round(gender === 'female' ? base - 161 : base + 5)
  })

  const bmiLabel = computed(() => {
    const b = parseFloat(bmi.value ?? '0')
    if (!bmi.value) return null
    if (b < 18.5) return { text: 'Gầy', color: '#32ADE6' }
    if (b < 25)   return { text: 'Bình thường', color: '#34C759' }
    if (b < 30)   return { text: 'Thừa cân', color: '#FF9500' }
    return { text: 'Béo phì', color: '#FF3B30' }
  })

  async function fetchProfile() {
    loading.value = true
    error.value = ''
    try {
      const res = await apiFetch<{ user: User }>('/user/profile')
      user.value = res.user
    } catch (err) {
      error.value = extractError(err)
    } finally {
      loading.value = false
    }
  }

  async function saveProfile(payload: UpdateProfilePayload): Promise<boolean> {
    saving.value = true
    error.value = ''
    try {
      const res = await apiFetch<{ user: User }>('/user/profile', {
        method: 'PATCH',
        body: payload,
      })
      user.value = res.user
      return true
    } catch (err) {
      error.value = extractError(err)
      return false
    } finally {
      saving.value = false
    }
  }

  async function uploadAvatar(file: File): Promise<string | null> {
    const body = new FormData()
    body.append('avatar', file)
    try {
      const res = await apiFetch<{ avatar_url: string }>('/user/avatar', {
        method: 'POST',
        body,
      })
      if (user.value) user.value.avatar_url = res.avatar_url
      return res.avatar_url
    } catch (err) {
      error.value = extractError(err)
      return null
    }
  }

  async function deleteAvatar(): Promise<boolean> {
    try {
      await apiFetch('/user/avatar', { method: 'DELETE' })
      if (user.value) user.value.avatar_url = null
      return true
    } catch (err) {
      error.value = extractError(err)
      return false
    }
  }

  return {
    loading, saving, error,
    age, bmi, bmr, bmiLabel,
    fetchProfile, saveProfile, uploadAvatar, deleteAvatar,
  }
}
```

> **Lưu ý `apiFetch` với FormData:** Cần bỏ `Content-Type: application/json` khi gửi FormData — sửa `api.ts` để skip `Content-Type` header nếu body là `FormData`.

---

## 7. Màn hình Profile `/profile`

**File hiện tại:** `frontend/src/pages/profile.vue`

### 7.1 Thay đổi cần làm

| # | Hạng mục | Trạng thái |
|---|----------|------------|
| 7.1 | Gọi `useProfile().fetchProfile()` khi component mount | 🔴 Chưa làm |
| 7.2 | Thay `bodyStats` hardcode → dùng `user.value` từ `useAuth()` | 🔴 Chưa làm |
| 7.3 | BMI, BMR tính từ `useProfile()` computed (có BMR female-aware) | 🔴 Chưa làm |
| 7.4 | Avatar: hiển thị `<img>` nếu có `avatar_url`, fallback initials | 🔴 Chưa làm |
| 7.5 | Nút edit avatar → gọi avatar picker flow | 🔴 Chưa làm |
| 7.6 | "Chỉnh sửa hồ sơ" button → navigate `/profile/edit` | 🔴 Chưa làm |
| 7.7 | Calorie streak từ `user.value.calorie_streak` | 🔴 Chưa làm |
| 7.8 | Loading skeleton khi fetch profile | 🔴 Chưa làm |

### 7.2 Avatar display logic

```vue
<!-- Trong profile card -->
<div class="w-16 h-16 rounded-full overflow-hidden bg-white/25 flex items-center justify-center">
  <img
    v-if="user?.avatar_url"
    :src="user.avatar_url"
    class="w-full h-full object-cover"
    alt="avatar"
  />
  <span v-else class="text-white font-bold text-[24px]">{{ displayAvatar }}</span>
</div>
```

---

## 8. Màn hình Chỉnh sửa hồ sơ `/profile/edit`

**File cần tạo:** `frontend/src/pages/profile/edit.vue`

### 8.1 Layout & UX

- Dùng layout `app` (BottomNav hiện)
- Middleware: `auth-strict`
- Header: Back button "‹ Hồ sơ" + tiêu đề "Chỉnh sửa hồ sơ" + nút "Lưu" (disabled khi không có thay đổi)
- iOS-style grouped sections giống trang profile hiện tại

### 8.2 Sections trong form

**Section 1 — Ảnh đại diện**
```
[ Avatar tròn to (80x80) ]  [Nút "Thay đổi ảnh" → mở picker]
                             [Nút "Xoá ảnh" nếu đang có avatar]
```

**Section 2 — Thông tin cơ bản**
```
Họ và tên      [Text input]
Email          [Text input, disabled — không cho đổi]
```

**Section 3 — Thể chất**
```
Giới tính      [Segmented: Nam | Nữ | Khác]
Năm sinh       [Number input, 1900–2015]
Chiều cao      [Number input + "cm" suffix]
Cân nặng       [Number input + "kg" suffix]
```

**Section 4 — Mục tiêu**
```
Calo/ngày      [Stepper ±100, range 1000–5000]
```

**Section 5 — Thông báo**
```
Nhắc buổi sáng [Time picker "HH:MM"]
Nhắc buổi tối  [Time picker "HH:MM"]
```

### 8.3 Validation rules (form)

```
name:        required | min 2 ký tự | max 100 ký tự
birth_year:  required | integer | 1900–2015
height_cm:   required | số | 50–300
weight_kg:   required | số | 20–500
calorie_goal: required | integer | 1000–5000
```

### 8.4 Submit flow

```
1. Validate form
2. Nếu hợp lệ → gọi useProfile().saveProfile(payload)
3. Nếu thành công → toast "Đã lưu thông tin" + navigateTo('/profile')
4. Nếu lỗi → hiển thị error message dưới form
```

### 8.5 Script setup skeleton

```typescript
definePageMeta({ layout: 'app', middleware: 'auth-strict' })

const { user } = useAuth()
const { saving, error, saveProfile } = useProfile()
const { success } = useToast()
const router = useRouter()

// Khởi tạo form từ user state hiện tại
const form = reactive({
  name:           user.value?.name ?? '',
  birth_year:     user.value?.birth_year ?? new Date().getFullYear() - 25,
  gender:         user.value?.gender ?? 'male',
  height_cm:      user.value?.height_cm ?? 170,
  weight_kg:      user.value?.weight_kg ?? 65,
  calorie_goal:   user.value?.calorie_goal ?? 2000,
  morning_notify: user.value?.morning_notify ?? '07:00',
  evening_notify: user.value?.evening_notify ?? '21:00',
})

const errors = reactive({ name: '', birth_year: '', height_cm: '', weight_kg: '' })

// Dirty check — chỉ enable Lưu khi có thay đổi
const isDirty = computed(() => {
  const u = user.value
  return form.name !== u?.name
    || form.birth_year !== u?.birth_year
    // ...etc
})

async function handleSave() {
  if (!validate()) return
  const ok = await saveProfile(form)
  if (ok) {
    success('Đã lưu thông tin')
    router.back()
  }
}
```

---

## 9. Chức năng thay đổi ảnh đại diện

### 9.1 Flow đầy đủ

```
1. User tap nút chỉnh sửa avatar (ở /profile hoặc /profile/edit)
2. Mở modal bottom sheet "Chọn ảnh đại diện":
   - [ 📷 Chụp ảnh ]
   - [ 🖼 Chọn từ thư viện ]
   - [ 🗑 Xoá ảnh đại diện ]  ← chỉ hiện nếu đang có avatar
   - [ Huỷ ]

3. Khi chọn file (input[type=file]):
   a. Hiện preview thumbnail
   b. Client-side resize xuống 512×512 max (Canvas API)
   c. Convert thành Blob webp (quality 0.85)
   d. Gọi uploadAvatar(blob)
   e. Nếu thành công → cập nhật avatar trong UI ngay (optimistic update)
   f. Toast "Đã cập nhật ảnh đại diện"

4. Khi "Xoá ảnh":
   a. Confirm dialog "Xoá ảnh đại diện?"
   b. Gọi deleteAvatar()
   c. Fallback về initials
```

### 9.2 Component `AvatarPicker`

**File cần tạo:** `frontend/src/components/profile/AvatarPicker.vue`

```vue
<script setup>
const props = defineProps<{ currentUrl: string | null }>()
const emit = defineEmits<{ upload: [file: File], delete: [] }>()

const fileInput = ref<HTMLInputElement>()
const showSheet = ref(false)

function openSheet() { showSheet.value = true }

async function onFileSelected(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  const resized = await resizeImage(file, 512)  // util function
  emit('upload', resized)
  showSheet.value = false
}

// resizeImage(file, maxSize): Canvas API resize + convert to webp
</script>
```

### 9.3 Hàm resize ảnh (client-side)

**File:** `frontend/src/utils/image.ts`

```typescript
export async function resizeImage(file: File, maxSize: number): Promise<File> {
  return new Promise((resolve) => {
    const img = new Image()
    const url = URL.createObjectURL(file)
    img.onload = () => {
      const canvas = document.createElement('canvas')
      const scale = Math.min(maxSize / img.width, maxSize / img.height, 1)
      canvas.width = img.width * scale
      canvas.height = img.height * scale
      canvas.getContext('2d')!.drawImage(img, 0, 0, canvas.width, canvas.height)
      URL.revokeObjectURL(url)
      canvas.toBlob(
        (blob) => resolve(new File([blob!], 'avatar.webp', { type: 'image/webp' })),
        'image/webp',
        0.85,
      )
    }
    img.src = url
  })
}
```

### 9.4 Fix `api.ts` cho FormData

Hiện tại `api.ts` luôn set `Content-Type: application/json`. Cần sửa để skip khi body là FormData:

```typescript
// Trong buildHeaders():
if (!headers.has('Content-Type') && !(init.body instanceof FormData)) {
  headers.set('Content-Type', 'application/json')
}
```

---

## 10. Danh sách file cần tạo / sửa

### Backend — Tạo mới

| File | Mô tả | Ưu tiên |
|------|--------|---------|
| `database/migrations/2026_06_19_000000_add_profile_fields_to_users_table.php` | Thêm cột profile vào users | 🔴 P0 |
| `app/Http/Controllers/Api/V1/UserController.php` | CRUD profile + avatar upload | 🔴 P0 |

### Backend — Sửa

| File | Việc cần làm | Ưu tiên |
|------|-------------|---------|
| `app/Models/User.php` | Thêm fillable + casts cho các trường mới | 🔴 P0 |
| `app/Http/Controllers/Api/V1/AuthController.php` | `register()` lưu profile fields; `formatUser()` trả thêm fields mới | 🔴 P0 |
| `routes/api.php` | Thêm routes `/user/profile`, `/user/avatar` | 🔴 P0 |

### Frontend — Tạo mới

| File | Mô tả | Ưu tiên |
|------|--------|---------|
| `src/composables/useProfile.ts` | Composable fetch/update profile + avatar | 🔴 P0 |
| `src/pages/profile/edit.vue` | Màn hình chỉnh sửa thông tin cá nhân | 🔴 P0 |
| `src/components/profile/AvatarPicker.vue` | Bottom sheet chọn/xoá ảnh đại diện | 🟡 P1 |
| `src/utils/image.ts` | Hàm `resizeImage()` client-side | 🟡 P1 |

### Frontend — Sửa

| File | Việc cần làm | Ưu tiên |
|------|-------------|---------|
| `src/types/auth.ts` | Thêm fields mới vào `User`, thêm `UpdateProfilePayload` | 🔴 P0 |
| `src/pages/profile.vue` | Dùng data thật từ `useAuth().user`, kết nối nút edit, avatar từ URL | 🔴 P0 |
| `src/utils/api.ts` | Skip `Content-Type` header khi body là `FormData` | 🟡 P1 |

---

## 11. Checklist tổng

### Phase 1 — Backend DB & API ✅ HOÀN THÀNH

- [x] Tạo migration `add_profile_fields_to_users_table`
- [x] Chạy `php artisan migrate` (qua Docker container)
- [x] Cập nhật `User` model: `#[Fillable]` + `casts()`
- [x] Tạo `UserController` với 4 methods (profile, updateProfile, uploadAvatar, deleteAvatar)
- [x] Thêm routes vào `routes/api_v1.php` (prefix `user`, middleware `auth:sanctum`)
- [x] Cập nhật `AuthController::formatUser()` trả đầy đủ 13 fields
- [x] Cập nhật `AuthController::register()` lưu birth_year, gender, height_cm, weight_kg, calorie_goal
- [x] Chạy `php artisan storage:link`
- [ ] Test API với Postman/curl

### Phase 2 — Frontend Types & Composable ✅ HOÀN THÀNH

- [x] Cập nhật `src/types/auth.ts` thêm 7 fields mới + `UpdateProfilePayload`
- [x] Tạo `src/composables/useProfile.ts`
  - [x] `fetchProfile()`
  - [x] `saveProfile(payload)`
  - [x] `uploadAvatar(file)`
  - [x] `deleteAvatar()`
  - [x] Computed: `age`, `bmi` (string|null), `bmr` (Mifflin-St Jeor, female-aware), `bmiLabel`
- [x] Sửa `src/utils/api.ts` skip Content-Type khi body là `FormData`

### Phase 3 — Màn hình Profile cập nhật ✅ HOÀN THÀNH

- [x] Sửa `src/pages/profile.vue`:
  - [x] Gọi `fetchProfile()` khi mount
  - [x] Hiện avatar từ `avatar_url` (fallback initials, `@error` handler nếu URL broken)
  - [x] Nút edit avatar: file input ẩn + dynamic import `resizeImage` + gọi `uploadAvatar()`
  - [x] BMI, BMR từ `useProfile()` computed (hiện "—" nếu thiếu dữ liệu)
  - [x] `calorie_streak` từ `user.value`
  - [x] "Chỉnh sửa hồ sơ" → `<NuxtLink to="/profile/edit">`
  - [x] Loading skeleton (3 pulse placeholder cards)
  - [x] Nút "Xoá ảnh đại diện" chỉ hiện khi có avatar

### Phase 4 — Màn hình Edit Profile ✅ HOÀN THÀNH

- [x] Tạo `src/pages/profile/edit.vue`:
  - [x] Form 5 sections (avatar, cơ bản, thể chất, mục tiêu, thông báo)
  - [x] Pre-fill form từ `user.value` khi mount
  - [x] Validate inline per-field (name, birth_year, height_cm, weight_kg)
  - [x] Dirty check → disable nút "Lưu" khi không có thay đổi
  - [x] Gọi `saveProfile()` + toast success + `router.back()`
  - [x] Error display banner khi API trả lỗi
  - [x] Avatar picker inline (preview + xoá) dùng `resizeImage` + `uploadAvatar`
  - [x] Segmented control giới tính (Nam / Nữ / Khác)
  - [x] `<input type="time">` cho morning/evening notify
  - [x] Nút Lưu ở header và cuối trang (cùng disabled logic)

### Phase 5 — Avatar Picker ✅ HOÀN THÀNH

- [x] Tạo `src/utils/image.ts` với `resizeImage()` (Canvas API, 512px max, webp 0.85)
- [x] Tạo `src/components/profile/AvatarPicker.vue`:
  - [x] Bottom sheet iOS-style với backdrop + slide-up animation (Teleport to body)
  - [x] Camera option (`capture="environment"`)
  - [x] Library option (jpeg/png/webp)
  - [x] Xoá option (chỉ hiện khi có avatar) → confirm state riêng
  - [x] Nút Huỷ
  - [x] `defineExpose({ open })` để parent gọi `avatarPicker.open()`
  - [x] Resize xảy ra trong component trước khi emit `upload`
- [x] Tích hợp vào `profile.vue` (thay file input thủ công)
- [x] Tích hợp vào `profile/edit.vue` (thay file input thủ công)
- [ ] Tạo `src/components/profile/AvatarPicker.vue`:
  - [ ] Bottom sheet iOS-style
  - [ ] Input file hidden (accept image/*)
  - [ ] Camera option (capture=camera)
  - [ ] Library option
  - [ ] Xoá option (nếu có avatar)
  - [ ] Preview trước khi upload
  - [ ] Gọi `uploadAvatar()` + toast
  - [ ] Xử lý delete + confirm

### Bug fixes & Edge cases dự kiến

- [ ] `apiFetch` với FormData — đã có fix kế hoạch ở Phase 2
- [ ] Avatar URL hết hạn / 404 → fallback về initials tự động (`@error` on `<img>`)
- [ ] User đăng nhập bằng Google có avatar từ Google — nên hiện từ `avatar_url` mà backend đã lưu từ OAuth flow
- [ ] Offline: `saveProfile()` fail với network error → toast lỗi thân thiện

---

## Notes

- **Đồng bộ `auth.user` state:** Sau mỗi `saveProfile()` hoặc `uploadAvatar()` thành công, `user.value` trong `useState('auth.user')` được cập nhật trực tiếp — không cần gọi lại `/auth/me`
- **BMR formula:** Mifflin-St Jeor (chính xác hơn Harris-Benedict): `(10 × weight) + (6.25 × height) − (5 × age) + 5` cho nam, `−161` cho nữ
- **`morning_notify` / `evening_notify`:** Lưu dạng `"HH:mm"` trong cả DB và API — dùng `<input type="time">` trên frontend
- **Avatar storage:** Backend cần `php artisan storage:link` để public URL hoạt động. File lưu tại `storage/app/public/avatars/`
- **Không cho đổi email:** Email là định danh duy nhất, gắn với OAuth — để đổi email cần flow riêng (verify email mới) → nằm ngoài scope spec này

---

*File này được cập nhật mỗi khi hoàn thành một task. Kiểm tra checklist trước khi bắt đầu làm.*
