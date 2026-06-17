# Spec: Authentication — Đăng nhập / Đăng ký / Quên mật khẩu

> **App:** CaloEye — Nuxt 3 + Vue 3 + Tailwind CSS 4 (iOS-style PWA)  
> **Cập nhật lần cuối:** 2026-06-17  
> **Trạng thái tổng:** ✅ TẤT CẢ PHASE HOÀN THÀNH — Sẵn sàng test

---

## Mục lục
1. [Tổng quan kiến trúc](#1-tổng-quan-kiến-trúc)
2. [API Contract (Backend)](#2-api-contract-backend)
3. [Frontend — Composable `useAuth`](#3-frontend--composable-useauth)
4. [Route Middleware](#4-route-middleware)
5. [Màn hình Đăng nhập `/auth/login`](#5-màn-hình-đăng-nhập-authlogin)
6. [Màn hình Đăng ký `/auth/register`](#6-màn-hình-đăng-ký-authregister)
7. [Màn hình Quên mật khẩu `/auth/forgot-password`](#7-màn-hình-quên-mật-khẩu-authforgot-password)
8. [Google OAuth](#8-google-oauth)
9. [Danh sách file cần tạo / sửa](#9-danh-sách-file-cần-tạo--sửa)
10. [Checklist tổng](#10-checklist-tổng)

---

## 1. Tổng quan kiến trúc

```
[Nuxt Frontend]                    [Backend API :8000/api/v1]
     │                                       │
     │── POST /auth/login ─────────────────▶ │ → { access_token, user }
     │── POST /auth/register ───────────────▶ │ → { access_token, user }
     │── POST /auth/forgot-password ────────▶ │ → { message }
     │── POST /auth/reset-password ─────────▶ │ → { message }
     │── GET  /auth/google ──────────────────▶ │ → redirect OAuth Google
     │── GET  /auth/google/callback ─────────▶ │ → redirect /?token=xxx
     │── GET  /auth/me ──────────────────────▶ │ → { user }
     │── POST /auth/logout ──────────────────▶ │ → 204
```

**Chiến lược token:**
- Backend trả `access_token` (JWT, 15 phút) + set `HttpOnly cookie refresh_token` (7 ngày)
- Frontend lưu `access_token` vào `useState` (in-memory, không localStorage để tránh XSS)
- Mỗi request gắn `Authorization: Bearer <access_token>` qua `apiFetch`
- Khi 401 → tự động gọi `POST /auth/refresh` để lấy token mới

**Google OAuth — redirect flow (hoạt động trên PWA):**
1. User bấm "Đăng nhập với Google"
2. Frontend redirect đến `{apiUrl}/auth/google?redirect_uri={appUrl}/auth/callback`
3. Backend xử lý Google OAuth, redirect về `/auth/callback?token=xxx`
4. Frontend nhận token, lưu vào state, redirect về `/home`

---

## 2. API Contract (Backend)

### 2.1 POST `/auth/login`
```json
// Request
{ "email": "user@example.com", "password": "secret123" }

// Response 200
{
  "access_token": "eyJ...",
  "token_type": "Bearer",
  "user": {
    "id": "uuid",
    "email": "user@example.com",
    "name": "Nguyễn Văn A",
    "avatar_url": null,
    "provider": "email"
  }
}

// Response 401
{ "detail": "Email hoặc mật khẩu không đúng" }

// Response 422
{ "detail": [{ "loc": ["body","email"], "msg": "field required" }] }
```

### 2.2 POST `/auth/register`
```json
// Request
{
  "email": "user@example.com",
  "password": "secret123",
  "name": "Nguyễn Văn A",
  "birth_year": 2000,
  "gender": "male",           // "male" | "female" | "other"
  "height_cm": 170,
  "weight_kg": 65,
  "calorie_goal": 2000,
  "morning_notify": "07:00",
  "evening_notify": "21:00"
}

// Response 201
{ "access_token": "...", "token_type": "Bearer", "user": { ... } }

// Response 409
{ "detail": "Email này đã được đăng ký" }
```

### 2.3 POST `/auth/forgot-password`
```json
// Request
{ "email": "user@example.com" }

// Response 200 (luôn trả 200 để tránh email enumeration)
{ "message": "Nếu email tồn tại, bạn sẽ nhận được hướng dẫn trong vài phút." }
```

### 2.4 POST `/auth/reset-password`
```json
// Request
{ "token": "reset-token-from-email", "new_password": "newSecret123" }

// Response 200
{ "message": "Mật khẩu đã được cập nhật" }

// Response 400
{ "detail": "Token không hợp lệ hoặc đã hết hạn" }
```

### 2.5 GET `/auth/google`
- Query params: `redirect_uri` (optional, default to configured value)
- Response: HTTP 302 → Google OAuth consent screen

### 2.6 GET `/auth/google/callback`
- Được gọi bởi Google sau khi user authorize
- Backend tạo/lấy user, tạo token
- Response: HTTP 302 → `{APP_URL}/auth/callback?token=xxx`

### 2.7 GET `/auth/me`
- Headers: `Authorization: Bearer <token>`
- Response 200: `{ user: { id, email, name, avatar_url, provider } }`
- Response 401: `{ detail: "Unauthorized" }`

### 2.8 POST `/auth/refresh`
- Cookie: `refresh_token` (tự động gửi do `credentials: 'include'`)
- Response 200: `{ access_token: "..." }`
- Response 401: refresh token hết hạn → force logout

### 2.9 POST `/auth/logout`
- Headers: `Authorization: Bearer <token>`
- Response 204: No Content (backend xóa refresh token)

---

## 3. Frontend — Composable `useAuth`

**File:** `frontend/src/composables/useAuth.ts`

```typescript
// Trạng thái
const user = useState<User | null>('auth.user', () => null)
const token = useState<string | null>('auth.token', () => null)
const isLoggedIn = computed(() => !!user.value)

// Methods
login(email, password)      → gọi POST /auth/login, set user+token, redirect /home
register(payload)            → gọi POST /auth/register, set user+token, redirect /home
forgotPassword(email)        → gọi POST /auth/forgot-password
resetPassword(token, pwd)   → gọi POST /auth/reset-password
loginWithGoogle()            → window.location.href = `${apiUrl}/auth/google`
handleOAuthCallback(token)   → set token, gọi GET /auth/me, redirect /home
logout()                     → gọi POST /auth/logout, clear state, redirect /auth/login
refreshToken()               → gọi POST /auth/refresh, cập nhật token
```

**Error handling:**
- Lỗi API → extract `detail` từ response body
- Network error → hiển thị "Không có kết nối mạng"
- 429 (rate limit) → "Thử lại sau X giây"

**Plugin auto-init:** `frontend/src/plugins/auth.client.ts`
- Khi app mount: gọi `GET /auth/me` để restore session từ HttpOnly cookie
- Nếu 401 → thử `POST /auth/refresh`
- Nếu vẫn fail → để `user = null` (không redirect, để middleware xử lý)

---

## 4. Route Middleware

**File:** `frontend/src/middleware/auth.ts`
```typescript
// Protected routes: tất cả trừ /auth/* và /
// Nếu chưa login → redirect /auth/login
// Nếu đã login mà vào /auth/* → redirect /home
```

Thêm vào mỗi auth page: `definePageMeta({ middleware: 'guest' })`  
Thêm vào app pages: `definePageMeta({ middleware: 'auth' })`

**Files:**
- `frontend/src/middleware/auth.ts` — bảo vệ /home, /scan, /result, /history, /chat, /profile
- `frontend/src/middleware/guest.ts` — redirect nếu đã login

---

## 5. Màn hình Đăng nhập `/auth/login`

**File hiện tại:** `frontend/src/pages/auth/login.vue` ✅ (UI đã có)

### 5.1 Những gì cần thêm / sửa

| # | Hạng mục | Trạng thái |
|---|----------|------------|
| 5.1 | Kết nối `handleLogin()` với `useAuth().login()` | 🔴 Chưa làm |
| 5.2 | Validation: email format, password min 6 ký tự | 🔴 Chưa làm |
| 5.3 | Hiển thị lỗi API dưới form (toast hoặc inline) | 🔴 Chưa làm |
| 5.4 | Kết nối nút "Đăng nhập với Google" với `loginWithGoogle()` | 🔴 Chưa làm |
| 5.5 | Disable button khi loading | ✅ Đã có |
| 5.6 | Link "Tiếp tục với tư cách Khách" — lưu `isGuest` flag | 🔴 Chưa làm |

### 5.2 Form validation rules

```
email:    required | valid email format
password: required | min 6 ký tự
```

### 5.3 UX Error states

```
Lỗi 401: "Email hoặc mật khẩu không đúng" — hiển thị dưới form màu đỏ
Lỗi 429: "Quá nhiều lần thử. Vui lòng đợi X giây."
Lỗi network: "Không thể kết nối. Kiểm tra lại mạng."
```

---

## 6. Màn hình Đăng ký `/auth/register`

**File hiện tại:** `frontend/src/pages/auth/register.vue` ✅ (UI đã có, 3 bước)

### 6.1 Những gì cần thêm / sửa

| # | Hạng mục | Trạng thái |
|---|----------|------------|
| 6.1 | Validate step 1 trước khi sang step 2 | 🔴 Chưa làm |
| 6.2 | Validate step 2 trước khi sang step 3 | 🔴 Chưa làm |
| 6.3 | Kết nối `nextStep()` cuối với `useAuth().register()` | 🔴 Chưa làm |
| 6.4 | Hiển thị lỗi API (email đã tồn tại, v.v.) | 🔴 Chưa làm |
| 6.5 | Nút "Đăng ký với Google" trên Step 1 | 🔴 Chưa làm |

### 6.2 Validation rules

**Step 1 — Tài khoản:**
```
email:           required | valid email
password:        required | min 8 ký tự | có chữ hoa + số
confirmPassword: required | === password
```

**Step 2 — Thông tin cá nhân:**
```
name:       required | min 2 ký tự
birth_year: required | 1900–2015
gender:     required
height_cm:  required | 50–300
weight_kg:  required | 20–500
```

**Step 3 — Mục tiêu:**
```
calorie_goal:    required (preset đã chọn)
morning_notify:  optional (default 07:00)
evening_notify:  optional (default 21:00)
```

---

## 7. Màn hình Quên mật khẩu `/auth/forgot-password`

**File hiện tại:** `frontend/src/pages/auth/forgot-password.vue` ✅ (UI đã có)

### 7.1 Những gì cần thêm / sửa

| # | Hạng mục | Trạng thái |
|---|----------|------------|
| 7.1 | Kết nối `handleSubmit()` với `useAuth().forgotPassword()` | 🔴 Chưa làm |
| 7.2 | Validate email format trước khi submit | 🔴 Chưa làm |
| 7.3 | Hiển thị lỗi validation inline | 🔴 Chưa làm |
| 7.4 | Countdown "Gửi lại sau X giây" (cooldown 60s) | 🔴 Chưa làm |

### 7.2 Màn hình Reset Password (mới)

Cần tạo thêm page `/auth/reset-password` — người dùng click link trong email được redirect về đây.

**File cần tạo:** `frontend/src/pages/auth/reset-password.vue`

```
Query params: ?token=xxx

UI:
- Input "Mật khẩu mới" (min 8 ký tự, có chữ hoa + số)
- Input "Xác nhận mật khẩu mới"
- Button "Đặt lại mật khẩu"
- Success state: "Mật khẩu đã được cập nhật" → redirect /auth/login sau 3s
- Error state: "Link đặt lại mật khẩu đã hết hạn"
```

---

## 8. Google OAuth

### 8.1 Flow chi tiết

```
1. User bấm "Đăng nhập với Google"
   └▶ loginWithGoogle() gọi:
      window.location.href = `${apiUrl}/auth/google?redirect_uri=${appUrl}/auth/callback`

2. Google hiện consent screen

3. Google redirect về: {apiUrl}/auth/google/callback?code=xxx

4. Backend xử lý:
   - Lấy user info từ Google
   - Tạo user mới HOẶC lấy user có email tương ứng
   - Tạo access_token + set refresh_token cookie
   - Redirect: {appUrl}/auth/callback?token=xxx

5. Frontend (page /auth/callback):
   - Nhận token từ query params
   - Gọi GET /auth/me để lấy user info
   - Set state: user, token
   - Redirect /home
```

### 8.2 File cần tạo

**`frontend/src/pages/auth/callback.vue`** — OAuth callback handler
```typescript
// Script setup:
const route = useRoute()
const { handleOAuthCallback } = useAuth()

onMounted(async () => {
  const token = route.query.token as string
  if (!token) return navigateTo('/auth/login')
  await handleOAuthCallback(token)
})

// Template: loading spinner toàn màn hình trong khi xử lý
```

### 8.3 Cấu hình môi trường

```env
# .env
NUXT_PUBLIC_API_URL=http://localhost:8000/api/v1
NUXT_PUBLIC_APP_URL=http://localhost:3000

# Backend cần cấu hình:
GOOGLE_CLIENT_ID=xxx
GOOGLE_CLIENT_SECRET=xxx
GOOGLE_REDIRECT_URI=http://localhost:8000/api/v1/auth/google/callback
```

---

## 9. Danh sách file cần tạo / sửa

### Tạo mới

| File | Mô tả | Ưu tiên |
|------|--------|---------|
| `src/composables/useAuth.ts` | Auth composable trung tâm | 🔴 P0 |
| `src/plugins/auth.client.ts` | Auto-restore session khi mount | 🔴 P0 |
| `src/middleware/auth.ts` | Bảo vệ routes cần login | 🔴 P0 |
| `src/middleware/guest.ts` | Redirect nếu đã login | 🔴 P0 |
| `src/pages/auth/callback.vue` | OAuth callback handler | 🔴 P0 |
| `src/pages/auth/reset-password.vue` | Form đặt lại mật khẩu | 🟡 P1 |
| `src/types/auth.ts` | TypeScript types (User, AuthResponse) | 🔴 P0 |

### Sửa

| File | Việc cần làm | Ưu tiên |
|------|-------------|---------|
| `src/pages/auth/login.vue` | Kết nối với `useAuth`, validate, error display, Google button | 🔴 P0 |
| `src/pages/auth/register.vue` | Validate từng step, kết nối với `useAuth.register()`, Google | 🔴 P0 |
| `src/pages/auth/forgot-password.vue` | Kết nối với `useAuth.forgotPassword()`, validate, cooldown | 🟡 P1 |
| `src/utils/api.ts` | Thêm auto-refresh token khi 401 (interceptor) | 🔴 P0 |
| `nuxt.config.ts` | Thêm `NUXT_PUBLIC_APP_URL` runtime config | 🟡 P1 |
| `src/pages/home.vue` | Thêm `definePageMeta({ middleware: 'auth' })` | 🔴 P0 |
| `src/pages/scan.vue` | Thêm `definePageMeta({ middleware: 'auth' })` | 🔴 P0 |
| `src/pages/chat.vue` | Thêm `definePageMeta({ middleware: 'auth' })` | 🔴 P0 |
| `src/pages/history.vue` | Thêm `definePageMeta({ middleware: 'auth' })` | 🔴 P0 |
| `src/pages/profile.vue` | Thêm `definePageMeta({ middleware: 'auth' })` | 🔴 P0 |

---

## 10. Checklist tổng

### Phase 1 — Foundation (P0) ✅ HOÀN THÀNH

- [x] Tạo `src/types/auth.ts` (User, AuthResponse, RegisterPayload)
- [x] Tạo `src/composables/useAuth.ts`
  - [x] `login(email, password)`
  - [x] `register(payload)`
  - [x] `loginWithGoogle()`
  - [x] `handleOAuthCallback(token)`
  - [x] `logout()`
  - [x] `refreshToken()`
  - [x] `restoreSession()`
  - [x] `extractError(err)` — chuẩn hoá lỗi API thành tiếng Việt
- [x] Sửa `src/utils/api.ts` — thêm 401 interceptor + shared refresh mutex
- [x] Thêm `appUrl` vào `nuxt.config.ts` runtime config
- [x] Tạo `src/plugins/auth.client.ts` — restore session khi mount
- [x] Tạo `src/middleware/auth.ts`
- [x] Tạo `src/middleware/guest.ts`
- [x] Tạo `src/pages/auth/callback.vue`

### Phase 2 — UI Integration (P0) ✅ HOÀN THÀNH

- [x] Sửa `login.vue`: kết nối useAuth, validate (email + min 6 ký tự), lỗi inline per-field + form error box, Google button wired, Apple button disabled (sắp ra mắt)
- [x] Sửa `register.vue`: validate step 1 (email, password min 8 + uppercase + number, confirm match) + step 2 (name, birthYear 1900–2015, gender, height, weight), Google shortcut button trên step 1, lỗi inline per-field + form error box trên step 3, kết nối `useAuth().register()`
- [x] Thêm middleware `auth` cho: home, scan, chat, history, profile, result
- [x] Thêm middleware `guest` cho: login, register, forgot-password

### Phase 3 — Forgot/Reset + Google (P1) ✅ HOÀN THÀNH

- [x] Sửa `forgot-password.vue`: kết nối `useAuth().forgotPassword()`, validate email format, cooldown 60s sau khi gửi, nút "Gửi lại" + đếm ngược, hiển thị email trong success state
- [x] Tạo `reset-password.vue`: form 2 field (mật khẩu mới + xác nhận), validate (min 8 + uppercase + số + match), gọi `useAuth().resetPassword()`, success state tự redirect /auth/login sau 3s + countdown, error state link "Yêu cầu link mới" → /auth/forgot-password
- [x] Tạo `frontend/.env.example` với đầy đủ env vars frontend + ghi chú backend
- [ ] Test full Google OAuth flow end-to-end (cần backend chạy)

### Phase 4 — Polish (P2) ✅ HOÀN THÀNH

- [x] `useToast` composable + `AppToast.vue` — iOS-style banner animation, 3 loại (success/error/info), auto-dismiss, Teleport to body
- [x] Toast thêm vào `layouts/auth.vue` + `layouts/app.vue`
- [x] Logout toast: "Đã đăng xuất thành công" (success)
- [x] Rate limit: 429 → "Quá nhiều lần thử" trong extractError; cooldown 60s trong forgot-password
- [x] Guest mode: `isGuest` state + `loginAsGuest()` + `middleware/auth-strict.ts` (history/chat/profile require full auth; home/scan/result cho phép guest)
- [x] "Tiếp tục với tư cách Khách" → gọi `loginAsGuest()` thay vì `navigateTo` trực tiếp (fix redirect loop)

### Bug fixes (ngoài spec, phát hiện khi review)

- [x] **api.ts**: Sau khi refresh fail + navigate → throw sentinel `auth:session_expired` thay vì original 401, tránh flash lỗi giả trên component đang unmount
- [x] **Race condition middleware**: Tất cả middleware giờ await `sessionReady` (với 5s timeout) trước khi check auth state — tránh false redirect khi plugin chưa hoàn tất `restoreSession`
- [x] **profile.vue logout**: Đã gọi `useAuth().logout()` thật (clear state + gọi API + toast), có loading state
- [x] **profile.vue user data**: Tên và email lấy từ `useAuth().user`, không còn hardcode
- [x] **Middleware phân tầng**: `auth` (guest OK) vs `auth-strict` (cần đăng nhập thật) — history, chat, profile dùng `auth-strict`

---

## Notes

- **Email enumeration:** `POST /auth/forgot-password` luôn trả 200 dù email không tồn tại
- **Google + Email cùng email:** Backend nên merge account (link provider) thay vì tạo duplicate
- **Guest mode:** Chỉ cho dùng /scan → /result; block /history, /chat, /profile; prompt login khi cần
- **Token storage:** KHÔNG dùng localStorage, dùng `useState` (memory) + HttpOnly cookie cho refresh token
- **PWA offline:** Các route /auth/* nên luôn có trong precache để hoạt động offline

---

*File này được cập nhật mỗi khi hoàn thành một task. Kiểm tra checklist trước khi bắt đầu làm.*
