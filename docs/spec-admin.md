# Spec: Admin — Thống kê / Quản lý người dùng / Cấu hình dịch vụ

> **App:** CaloEye — Laravel API (`/api/v1`, Sanctum) + Vue 3 SPA (vue-router + Pinia, `resources/js/`)
> **Cập nhật lần cuối:** 2026-06-27
> **Trạng thái tổng:** 🟢 ĐÃ IMPLEMENT (Phase 1–5) — Lint PHP + type-check + build frontend đều pass.
> ⚠️ **Còn lại:** Chạy `php artisan migrate` trong môi trường PHP ≥ 8.4.1 của bạn (sandbox chỉ có PHP 8.3 nên chưa migrate được), sau đó `php artisan app:make-admin <email>` để tạo admin đầu tien.

---

## Mục lục
1. [Tổng quan kiến trúc](#1-tổng-quan-kiến-trúc)
2. [Phân quyền (RBAC)](#2-phân-quyền-rbac)
3. [API Contract — Thống kê](#3-api-contract--thống-kê)
4. [API Contract — Quản lý người dùng](#4-api-contract--quản-lý-người-dùng)
5. [API Contract — Cấu hình dịch vụ](#5-api-contract--cấu-hình-dịch-vụ)
6. [Frontend — Layout & Routing Admin](#6-frontend--layout--routing-admin)
7. [Màn hình Dashboard `/admin`](#7-màn-hình-dashboard-admin)
8. [Màn hình Quản lý người dùng `/admin/users`](#8-màn-hình-quản-lý-người-dùng-adminusers)
9. [Màn hình Cấu hình dịch vụ `/admin/settings`](#9-màn-hình-cấu-hình-dịch-vụ-adminsettings)
10. [Audit log](#10-audit-log)
11. [Danh sách file cần tạo / sửa](#11-danh-sách-file-cần-tạo--sửa)
12. [Checklist tổng](#12-checklist-tổng)
13. [Notes & bảo mật](#13-notes--bảo-mật)

---

## 1. Tổng quan kiến trúc

```
[Vue SPA /admin/*]                 [Backend API :8000/api/v1/admin]
  (layout 'admin',                          │ middleware: auth:sanctum + admin
   middleware 'admin')                      │
     │── GET  /admin/stats ───────────────▶ │ → tổng quan KPI + chuỗi thời gian
     │── GET  /admin/users ───────────────▶ │ → danh sách phân trang + filter
     │── GET  /admin/users/{id} ──────────▶ │ → chi tiết 1 user
     │── PATCH /admin/users/{id} ─────────▶ │ → sửa profile/role/status
     │── POST /admin/users/{id}/suspend ──▶ │ → khoá / mở khoá
     │── DELETE /admin/users/{id} ────────▶ │ → xoá mềm (soft delete)
     │── GET  /admin/settings ────────────▶ │ → toàn bộ cấu hình runtime
     │── PUT  /admin/settings ────────────▶ │ → cập nhật cấu hình
     │── POST /admin/settings/test/{svc} ─▶ │ → kiểm tra kết nối dịch vụ
     │── GET  /admin/audit-logs ──────────▶ │ → nhật ký hành động admin
```

**Nguyên tắc chính:**
- Tất cả route admin nằm dưới prefix `/api/v1/admin`, bảo vệ bởi `auth:sanctum` **và** middleware `admin` (check `role === 'admin'`).
- Frontend dùng layout riêng `admin` (sidebar desktop-first, không phải iOS bottom-nav) + middleware route `admin`.
- Cấu hình dịch vụ chỉnh sửa được lúc runtime lưu ở bảng `settings` (key-value JSON, có cache). **Secret** (API key, client secret) chỉ hiển thị dạng masked, không trả raw ra API.
- Mọi hành động ghi/sửa của admin được ghi vào `admin_audit_logs`.

---

## 2. Phân quyền (RBAC)

### 2.1 Migration — thêm cột `role` + `status` vào `users`

**File cần tạo:** `database/migrations/2026_06_27_000001_add_role_status_to_users_table.php`

```php
$table->string('role', 20)->default('user')->index();   // 'user' | 'admin'
$table->string('status', 20)->default('active')->index(); // 'active' | 'suspended'
$table->softDeletes(); // cột deleted_at cho xoá mềm
```

- Cập nhật `User` model: thêm `role`, `status` vào `#[Fillable]`; thêm `use SoftDeletes;`.
- Thêm helper `User::isAdmin(): bool => $this->role === 'admin'`.
- Seeder/command tạo admin đầu tiên: `php artisan app:make-admin {email}` (Console command) — set `role = 'admin'`.

### 2.2 Middleware backend `admin`

**File cần tạo:** `app/Http/Middleware/EnsureUserIsAdmin.php`

```php
public function handle(Request $request, Closure $next): Response
{
    $user = $request->user();
    if (! $user || ! $user->isAdmin() || $user->status !== 'active') {
        abort(403, 'Không có quyền truy cập trang quản trị');
    }
    return $next($request);
}
```

- Đăng ký alias `admin` trong `bootstrap/app.php` (`$middleware->alias([...])`).
- Áp dụng cho cả nhóm route admin: `Route::middleware(['auth:sanctum', 'admin'])`.

### 2.3 Frontend — đưa `role`/`status` vào `/auth/me`

- `AuthController::me` trả thêm `role`, `status` trong object `user`.
- `stores/auth.ts`: thêm getter `isAdmin = computed(() => user.value?.role === 'admin')`.

### 2.4 Frontend middleware route `admin`

- Trong `router/index.ts` `beforeEach`: thêm nhánh `mw === 'admin'`:
  ```ts
  if (mw === 'admin' && (!auth.isLoggedIn || !auth.isAdmin)) return '/home'
  ```

---

## 3. API Contract — Thống kê

### 3.1 GET `/admin/stats`
Query: `?range=7d|30d|90d` (default `30d`).

```json
// Response 200
{
  "kpi": {
    "total_users": 1820,
    "new_users_today": 12,
    "active_users_7d": 430,        // có last_seen_at trong 7 ngày
    "suspended_users": 5,
    "total_meal_logs": 48230,
    "meal_logs_today": 318,
    "ai_food_analyses_today": 290,  // số lần gọi /food/analyze + /food/detect
    "ai_chat_messages_today": 142,
    "push_sent_today": 540,
    "active_streaks": 210            // user có streak > 0
  },
  "series": {
    "new_users":  [{ "date": "2026-06-01", "count": 8 }, ...],
    "meal_logs":  [{ "date": "2026-06-01", "count": 280 }, ...],
    "ai_calls":   [{ "date": "2026-06-01", "count": 310 }, ...]
  },
  "breakdown": {
    "by_provider": { "email": 1200, "google": 540, "facebook": 80 },
    "by_gender":   { "male": 900, "female": 870, "other": 50 }
  }
}
```

- Tính từ các bảng: `users`, `meal_logs`, `notification_logs`, `user_streaks`.
- **Hiệu năng:** cache kết quả 5 phút (`Cache::remember`), key theo `range`. AI call/day tổng hợp từ một bảng đếm hoặc từ `notification_logs`/log riêng (xem Notes nếu chưa có bảng đếm AI).

### 3.2 GET `/admin/stats/export` (P2)
- Query: `?type=users|meal_logs&range=...&format=csv`
- Response: file CSV (stream download).

---

## 4. API Contract — Quản lý người dùng

### 4.1 GET `/admin/users`
Query params:
```
?search=          // tìm theo name/email
&role=user|admin
&status=active|suspended
&provider=email|google|facebook
&sort=created_at|last_seen_at|name   (mặc định created_at)
&order=asc|desc                      (mặc định desc)
&page=1&per_page=20
```

```json
// Response 200
{
  "data": [
    {
      "id": 101,
      "name": "Nguyễn Văn A",
      "email": "a@example.com",
      "avatar_url": null,
      "provider": "email",
      "role": "user",
      "status": "active",
      "calorie_streak": 14,
      "meal_logs_count": 230,
      "last_seen_at": "2026-06-27T03:10:00Z",
      "created_at": "2026-03-01T09:00:00Z"
    }
  ],
  "meta": { "current_page": 1, "per_page": 20, "total": 1820, "last_page": 91 }
}
```

### 4.2 GET `/admin/users/{id}`
```json
// Response 200 — chi tiết đầy đủ
{
  "id": 101,
  "name": "...", "email": "...", "avatar_url": null,
  "provider": "email", "role": "user", "status": "active",
  "birth_year": 2000, "gender": "male", "height_cm": 170, "weight_kg": 65,
  "calorie_goal": 2000, "calorie_streak": 14,
  "notify": { "morning": true, "midday": false, "evening": true, "email_reengagement": true },
  "stats": { "meal_logs": 230, "water_logs": 88, "plans": 3, "passkeys": 1 },
  "last_seen_at": "...", "created_at": "...", "updated_at": "..."
}
```

### 4.3 PATCH `/admin/users/{id}`
```json
// Request (chỉ gửi field muốn đổi)
{ "name": "...", "email": "...", "role": "admin", "calorie_goal": 2200 }

// Response 200 → user object đã cập nhật
// Response 422 → lỗi validation (email trùng, role không hợp lệ)
```
- **Không** cho đổi password ở đây; dùng endpoint reset riêng (4.6).
- Đổi `role` của chính mình bị chặn (tránh tự gỡ quyền admin cuối cùng).

### 4.4 POST `/admin/users/{id}/suspend`
```json
// Request
{ "reason": "Spam nội dung" }    // optional
// Response 200 → { "status": "suspended" }
```
- Set `status = 'suspended'`. User bị suspended: middleware `auth:sanctum` vẫn pass nhưng thêm check → trả 403 `account_suspended` cho mọi API non-auth-logout; FE tự logout.
- Không cho suspend chính mình / user admin khác (trừ super-admin — xem Notes).

### 4.5 POST `/admin/users/{id}/restore`
- Mở khoá: `status = 'active'`. Hoặc khôi phục soft-deleted (`restore()`).

### 4.6 POST `/admin/users/{id}/reset-password`
```json
// Response 200
{ "message": "Đã gửi email đặt lại mật khẩu" }
```
- Gửi mail reset (tái dùng flow `forgot-password`), không trả mật khẩu mới ra response.

### 4.7 DELETE `/admin/users/{id}`
- Soft delete (`deleted_at`). Không cho xoá chính mình. Response 204.

---

## 5. API Contract — Cấu hình dịch vụ

### 5.1 Bảng `settings` (key-value runtime)

**File cần tạo:** `database/migrations/2026_06_27_000002_create_settings_table.php`
```php
$table->string('key')->primary();   // vd 'ai.provider'
$table->json('value')->nullable();
$table->string('group')->index();   // 'ai' | 'notifications' | 'mail' | 'oauth' | 'rate_limit' | 'features'
$table->timestamps();
```

**File cần tạo:** `app/Services/SettingsService.php`
- `get(string $key, $default = null)` — đọc, cache vĩnh viễn đến khi `set` (cache key `settings.all`).
- `set(string $key, $value)` — ghi DB + flush cache.
- `all(): array` — group theo `group`.
- Các service hiện có (`FoodAnalysisService`, `ChatService`, `FcmService`) đọc cấu hình qua `SettingsService` với **fallback về `config()`/`env()`** khi key chưa set.

### 5.2 GET `/admin/settings`
```json
// Response 200 — secret luôn masked
{
  "ai": {
    "provider": "gemini",                 // 'gemini'
    "model": "gemini-2.0-flash",
    "api_key": "AIza••••••••3xQ",          // masked, read-only hiển thị
    "temperature": 0.4,
    "max_tokens": 2048,
    "food_analysis_enabled": true,
    "chat_enabled": true
  },
  "rate_limit": {
    "food_analyze_per_min": 10,
    "chat_per_min": 15,
    "plan_generate_per_min": 5
  },
  "notifications": {
    "fcm_enabled": true,
    "fcm_project_id": "caloeye-xxxx",
    "morning_default": "07:00",
    "evening_default": "21:00",
    "reengagement_days": 7
  },
  "mail": {
    "from_address": "no-reply@caloeye.app",
    "from_name": "CaloEye",
    "reengagement_enabled": true
  },
  "oauth": {
    "google_enabled": true,
    "facebook_enabled": false
  },
  "features": {
    "registration_open": true,
    "guest_mode_enabled": true,
    "maintenance_mode": false
  }
}
```

### 5.3 PUT `/admin/settings`
```json
// Request — gửi nguyên 1 group hoặc nhiều key (partial update)
{ "ai": { "model": "gemini-2.0-flash", "temperature": 0.5 },
  "rate_limit": { "chat_per_min": 20 } }

// Response 200 → object settings đầy đủ (đã masked)
// Response 422 → validation (temperature 0–2, rate > 0, time HH:mm...)
```
- **Secret** (api_key, client_secret): chỉ cập nhật khi client gửi giá trị mới *không phải* chuỗi masked; nếu gửi lại chuỗi masked → giữ nguyên giá trị cũ.
- `maintenance_mode = true` → API non-admin trả 503 `maintenance`.

### 5.4 POST `/admin/settings/test/{service}`
- `{service}` ∈ `ai | fcm | mail`.
- Thực hiện 1 lệnh kiểm tra nhẹ (AI: ping model bằng prompt ngắn; fcm: validate credentials; mail: gửi mail test tới admin hiện tại).
```json
// Response 200 → { "ok": true, "latency_ms": 320, "message": "Kết nối Gemini thành công" }
// Response 200 → { "ok": false, "message": "API key không hợp lệ (401)" }
```

---

## 6. Frontend — Layout & Routing Admin

### 6.1 Layout `admin`
**File cần tạo:** `resources/js/layouts/AdminLayout.vue`
- Desktop-first: sidebar trái (Dashboard, Người dùng, Cấu hình, Audit log), topbar (tên admin + nút đăng xuất).
- Responsive: sidebar thu gọn thành drawer trên mobile.
- Đăng ký trong cơ chế layout hiện tại (xem cách `layout: 'app' | 'auth'` được resolve trong `App.vue`).

### 6.2 Routes (thêm vào `router/index.ts`)
```ts
{ path: '/admin',          component: () => import('@/pages/admin/Dashboard.vue'), meta: { layout: 'admin', middleware: 'admin' } },
{ path: '/admin/users',    component: () => import('@/pages/admin/Users.vue'),     meta: { layout: 'admin', middleware: 'admin' } },
{ path: '/admin/users/:id',component: () => import('@/pages/admin/UserDetail.vue'),meta: { layout: 'admin', middleware: 'admin' } },
{ path: '/admin/settings', component: () => import('@/pages/admin/Settings.vue'),  meta: { layout: 'admin', middleware: 'admin' } },
{ path: '/admin/audit-logs',component: () => import('@/pages/admin/AuditLogs.vue'),meta: { layout: 'admin', middleware: 'admin' } },
```

### 6.3 Composable `useAdmin`
**File cần tạo:** `resources/js/composables/useAdmin.ts` — gọi API qua `utils/api`:
```
fetchStats(range)            → GET /admin/stats
fetchUsers(params)           → GET /admin/users
fetchUser(id)                → GET /admin/users/{id}
updateUser(id, payload)      → PATCH /admin/users/{id}
suspendUser(id, reason)      → POST /admin/users/{id}/suspend
restoreUser(id)              → POST /admin/users/{id}/restore
resetUserPassword(id)        → POST /admin/users/{id}/reset-password
deleteUser(id)               → DELETE /admin/users/{id}
fetchSettings()              → GET /admin/settings
saveSettings(payload)        → PUT /admin/settings
testService(svc)             → POST /admin/settings/test/{svc}
fetchAuditLogs(params)       → GET /admin/audit-logs
```

---

## 7. Màn hình Dashboard `/admin`

**File cần tạo:** `resources/js/pages/admin/Dashboard.vue`

| # | Thành phần | Mô tả |
|---|-----------|-------|
| 7.1 | Hàng KPI cards | Tổng user, user mới hôm nay, active 7 ngày, meal logs hôm nay, AI calls hôm nay, push hôm nay |
| 7.2 | Bộ chọn range | 7d / 30d / 90d → refetch |
| 7.3 | Biểu đồ đường | New users / Meal logs / AI calls theo ngày (dùng lib chart nhẹ, vd `vue-chartjs` hoặc SVG tự vẽ) |
| 7.4 | Donut breakdown | Theo provider + theo gender |
| 7.5 | Trạng thái dịch vụ | Badge xanh/đỏ: AI, FCM, Mail (lấy từ test cache gần nhất hoặc nút test nhanh) |
| 7.6 | Loading skeleton + error state | Khi fetch lỗi hiển thị retry |

---

## 8. Màn hình Quản lý người dùng `/admin/users`

**File cần tạo:** `resources/js/pages/admin/Users.vue` + `resources/js/pages/admin/UserDetail.vue`

### 8.1 Danh sách (`Users.vue`)
| # | Hạng mục | Mô tả |
|---|----------|-------|
| 8.1 | Thanh tìm kiếm (debounce 300ms) | search name/email |
| 8.2 | Filter | role, status, provider |
| 8.3 | Bảng | avatar, tên, email, provider badge, role badge, status badge, streak, meal logs, last seen |
| 8.4 | Sort | click header (created_at, last_seen_at, name) |
| 8.5 | Phân trang | server-side, hiển thị tổng |
| 8.6 | Action menu mỗi dòng | Xem chi tiết, Khoá/Mở, Reset mật khẩu, Xoá (confirm modal) |
| 8.7 | Empty state | "Không tìm thấy người dùng" |

### 8.2 Chi tiết (`UserDetail.vue`)
| # | Hạng mục | Mô tả |
|---|----------|-------|
| 8.8 | Header | avatar, tên, email, badges, ngày tạo, last seen |
| 8.9 | Form sửa | name, email, role (select), calorie_goal, profile cơ bản |
| 8.10 | Card stats | meal_logs, water_logs, plans, passkeys |
| 8.11 | Card thông báo | trạng thái các loại notify (read-only) |
| 8.12 | Nút nguy hiểm | Khoá tài khoản / Reset mật khẩu / Xoá — đều có confirm modal + lý do |
| 8.13 | Guard tự-thao-tác | Ẩn nút xoá/đổi-role với chính mình |

---

## 9. Màn hình Cấu hình dịch vụ `/admin/settings`

**File cần tạo:** `resources/js/pages/admin/Settings.vue`

Bố cục theo tab/section group:

| Section | Trường | Ghi chú |
|---------|--------|---------|
| **AI (Gemini)** | provider, model, api_key (masked), temperature (slider 0–2), max_tokens, bật/tắt food_analysis & chat | Nút "Test kết nối" → `/test/ai` |
| **Rate limit** | food_analyze_per_min, chat_per_min, plan_generate_per_min | number input > 0 |
| **Thông báo** | fcm_enabled, fcm_project_id (read-only), morning_default, evening_default, reengagement_days | Nút "Test FCM" → `/test/fcm` |
| **Email** | from_address, from_name, reengagement_enabled | Nút "Gửi mail test" → `/test/mail` |
| **OAuth** | google_enabled, facebook_enabled (toggle) | Secret hiển thị masked |
| **Tính năng** | registration_open, guest_mode_enabled, maintenance_mode | maintenance_mode có cảnh báo đỏ trước khi bật |

**UX:**
- Mỗi section có nút "Lưu" riêng (partial PUT) + toast "Đã lưu cấu hình".
- Field secret: placeholder hiển thị giá trị masked; chỉ gửi lên khi user nhập mới.
- Validation client trùng với server (temperature 0–2, time HH:mm, số > 0).
- Toggle `maintenance_mode` → modal xác nhận "App sẽ tạm ngưng với người dùng thường".

---

## 10. Audit log

### 10.1 Migration
**File cần tạo:** `database/migrations/2026_06_27_000003_create_admin_audit_logs_table.php`
```php
$table->id();
$table->foreignId('admin_id')->constrained('users');
$table->string('action');         // 'user.suspend', 'settings.update', 'user.delete'...
$table->string('target_type')->nullable();  // 'user' | 'settings'
$table->string('target_id')->nullable();
$table->json('meta')->nullable(); // payload tóm tắt (KHÔNG lưu secret)
$table->string('ip')->nullable();
$table->timestamps();
```

### 10.2 Ghi log
- Helper `AuditLogger::log($action, $target, $meta)` gọi trong controller admin sau mỗi thao tác ghi.
- **Không** ghi giá trị secret vào `meta`.

### 10.3 GET `/admin/audit-logs`
- Query: `?admin_id=&action=&page=` → phân trang, mới nhất trước.
- Trang `AuditLogs.vue`: bảng thời gian / admin / action / target / IP.

---

## 11. Danh sách file cần tạo / sửa

### Backend — tạo mới
| File | Mô tả | Ưu tiên |
|------|--------|---------|
| `database/migrations/..._add_role_status_to_users_table.php` | role, status, softDeletes | 🔴 P0 |
| `database/migrations/..._create_settings_table.php` | key-value runtime config | 🟡 P1 |
| `database/migrations/..._create_admin_audit_logs_table.php` | audit log | 🟡 P1 |
| `app/Http/Middleware/EnsureUserIsAdmin.php` | chặn non-admin | 🔴 P0 |
| `app/Http/Controllers/Api/V1/Admin/StatsController.php` | thống kê | 🔴 P0 |
| `app/Http/Controllers/Api/V1/Admin/UserController.php` | CRUD user | 🔴 P0 |
| `app/Http/Controllers/Api/V1/Admin/SettingsController.php` | cấu hình | 🟡 P1 |
| `app/Http/Controllers/Api/V1/Admin/AuditLogController.php` | xem audit | 🟢 P2 |
| `app/Services/SettingsService.php` | đọc/ghi settings + cache | 🟡 P1 |
| `app/Services/AuditLogger.php` | ghi audit log | 🟡 P1 |
| `app/Console/Commands/MakeAdmin.php` | `app:make-admin {email}` | 🔴 P0 |
| `app/Http/Resources/Admin/UserResource.php` | format user (an toàn) | 🟡 P1 |

### Backend — sửa
| File | Việc | Ưu tiên |
|------|------|---------|
| `app/Models/User.php` | thêm role/status fillable, `SoftDeletes`, `isAdmin()` | 🔴 P0 |
| `routes/api_v1.php` | thêm nhóm `Route::middleware(['auth:sanctum','admin'])->prefix('admin')` | 🔴 P0 |
| `bootstrap/app.php` | đăng ký alias middleware `admin` + check suspended/maintenance | 🔴 P0 |
| `app/Http/Controllers/Api/V1/AuthController.php` | `me()` trả thêm `role`, `status` | 🔴 P0 |
| `app/Services/FoodAnalysisService.php`, `ChatService.php`, `FcmService.php` | đọc qua `SettingsService` (fallback config) | 🟡 P1 |

### Frontend — tạo mới
| File | Mô tả | Ưu tiên |
|------|--------|---------|
| `resources/js/layouts/AdminLayout.vue` | sidebar + topbar | 🔴 P0 |
| `resources/js/composables/useAdmin.ts` | gọi API admin | 🔴 P0 |
| `resources/js/pages/admin/Dashboard.vue` | thống kê | 🔴 P0 |
| `resources/js/pages/admin/Users.vue` | danh sách user | 🔴 P0 |
| `resources/js/pages/admin/UserDetail.vue` | chi tiết/sửa user | 🔴 P0 |
| `resources/js/pages/admin/Settings.vue` | cấu hình dịch vụ | 🟡 P1 |
| `resources/js/pages/admin/AuditLogs.vue` | nhật ký | 🟢 P2 |
| `resources/js/types/admin.ts` | TS types (Stats, AdminUser, Settings...) | 🔴 P0 |

### Frontend — sửa
| File | Việc | Ưu tiên |
|------|------|---------|
| `resources/js/router/index.ts` | thêm 5 route admin + nhánh middleware `admin` | 🔴 P0 |
| `resources/js/stores/auth.ts` | getter `isAdmin`, lưu `role`/`status` | 🔴 P0 |
| `resources/js/App.vue` | resolve layout `admin` | 🔴 P0 |
| `resources/js/components/profile/...` hoặc Profile.vue | link vào `/admin` nếu `isAdmin` | 🟢 P2 |

---

## 12. Checklist tổng

### Phase 1 — Nền tảng RBAC (P0) ✅
- [x] Migration `role` + `status` + `suspend_reason` + `softDeletes` vào `users`
- [x] Cập nhật `User` model (fillable, SoftDeletes, `isAdmin()`, `isSuspended()`)
- [x] Middleware `EnsureUserIsAdmin` (alias `admin`) + `CheckAccountStatus` (suspended + maintenance)
- [x] Command `app:make-admin {email}` _(chạy sau khi migrate)_
- [x] `AuthController::me` trả `role`, `status`
- [x] `stores/auth.ts` getter `isAdmin`; router nhánh middleware `admin`
- [x] `AdminLayout.vue` (sidebar desktop, toggle `body.admin-mode` bỏ giới hạn 430px) + resolve layout trong `App.vue`
- [x] Link "Trang quản trị" trong `Profile.vue` (chỉ hiện với admin)

### Phase 2 — Thống kê (P0) ✅
- [x] `StatsController@index` + cache 5 phút
- [x] Route `GET /admin/stats`
- [x] Bảng `usage_events` + `UsageTracker` ghi nhận lượt gọi AI (analyze/detect/chat) → số liệu AI thật
- [x] `useAdmin.fetchStats` + `Dashboard.vue` (KPI cards, range 7/30/90d, 3 line chart SVG, breakdown provider/gender, skeleton/error)

### Phase 3 — Quản lý người dùng (P0) ✅
- [x] `Admin/UserController` (index/show/update/suspend/restore/reset-password/destroy)
- [x] Routes nhóm `/admin/users`
- [x] `Users.vue` (search debounce, filter, sort, pagination, action menu, confirm)
- [x] `UserDetail.vue` (form sửa, stats, card thông báo, nút nguy hiểm, guard tự-thao-tác)
- [x] Guard: không tự xoá / không tự gỡ role / không hạ admin active cuối / chặn suspend+delete admin

### Phase 4 — Cấu hình dịch vụ (P1) ✅
- [x] Migration `settings` + `Setting` model + `SettingsService` (cache, fallback config, mask secret)
- [x] `Admin/SettingsController` (index/update/test ai|fcm|mail) + masking secret (giữ giá trị cũ nếu gửi chuỗi mask)
- [x] `FoodAnalysisService` + `ChatService` đọc api_key/model qua `SettingsService`; `register` tôn trọng `features.registration_open`; maintenance_mode qua middleware
- [x] `Settings.vue` (6 section, save riêng, test buttons, secret masked, maintenance confirm)

### Phase 5 — Audit log (P2) ✅
- [x] Migration `admin_audit_logs` + `AdminAuditLog` model + `AuditLogger`
- [x] Ghi log trong UserController (update/suspend/restore/reset_password/delete) + SettingsController (update)
- [x] `AuditLogController@index` + `AuditLogs.vue` (filter action, pagination)
- [ ] `GET /admin/stats/export` CSV _(chưa làm — optional)_

### Phase 6 — Gửi thông báo broadcast (P1) ✅
- [x] Migration `notification_campaigns` + model + `NotificationAudience` (dựng query phân khúc: audience all/segment, role, provider, gender, activity 7/30 ngày, has_streak, only_subscribed; luôn loại suspended)
- [x] Job `SendBroadcastNotification` (queue=database, chunk 200, tạo log + FCM multicast + dọn token hỏng, cập nhật sent/push/status)
- [x] `Admin/NotificationController` (preview đếm audience, send tạo campaign + dispatch job + audit, index lịch sử) + routes
- [x] `Notifications.vue` (soạn nội dung + preview noti, chọn phân khúc, đếm người nhận realtime, gửi, bảng lịch sử trạng thái) + nav link + route
- [x] Verify Docker: preview all=8/google=1, send→queue→done sent=8 push=2, 8 broadcast logs, audit ghi `notification.broadcast`

### Còn lại (môi trường)
- [ ] `php artisan migrate` (cần PHP ≥ 8.4.1 — sandbox chỉ có 8.3)
- [ ] `php artisan app:make-admin <email>` để tạo admin đầu tiên
- [ ] Test end-to-end trên môi trường thật

---

## 13. Notes & bảo mật

- **Super-admin (tuỳ chọn):** nếu cần phân tầng, thêm role `super_admin` để quản admin khác. Mặc định spec dùng 2 mức `user` / `admin`; admin **không** thao tác được lên admin khác.
- **Admin cuối cùng:** chặn hạ role / xoá / suspend nếu đó là admin active duy nhất còn lại.
- **Secret không lộ:** GET settings luôn masked; PUT chỉ ghi đè khi giá trị khác chuỗi masked; audit log không chứa secret.
- **Suspended user:** thêm check trong middleware → trả 403 `account_suspended`; FE bắt mã này để auto-logout. Logout vẫn cho phép.
- **Maintenance mode:** API non-admin trả 503 `maintenance`; admin vẫn truy cập được.
- **Phân trang & N+1:** dùng `withCount('mealLogs')` và eager-load để tránh N+1 khi liệt kê user.
- **Đếm AI calls:** hiện chưa có bảng đếm riêng cho `/food/analyze`, `/food/detect`, `/chat`. Hai lựa chọn: (a) tạo bảng `usage_events` ghi mỗi lần gọi AI (khuyến nghị, phục vụ thống kê chính xác), hoặc (b) suy ra gần đúng từ `meal_logs` tạo trong ngày. Spec mặc định (a) — thêm vào Phase 2 nếu cần số liệu AI thật.
- **Rate limit động:** muốn rate-limit đọc từ `settings` thay vì hằng số trong route, cần custom limiter trong `RouteServiceProvider`/`bootstrap` đọc `SettingsService`. Nếu chưa làm, các giá trị rate-limit trong Settings chỉ mang tính hiển thị/định hướng cho tới khi nối limiter động (ghi rõ trong UI).
- **CSRF/SPA:** API dùng Sanctum token (Bearer) như phần còn lại của app — admin tái dùng cùng cơ chế, không cần session cookie riêng.

---

*File này được cập nhật mỗi khi hoàn thành một task. Kiểm tra checklist trước khi bắt đầu làm.*
