# Spec: Push Notifications & Email Re-engagement

> **App:** CaloEye — Nuxt 3 + Vue 3 + Tailwind CSS 4 (iOS-style PWA)  
> **Cập nhật lần cuối:** 2026-06-21  
> **Trạng thái tổng:** 🟡 Phase 1–4 hoàn thành — Phase 5 chờ APNs key (Apple Developer account)

---

## Mục lục
1. [Tổng quan kiến trúc](#1-tổng-quan-kiến-trúc)
2. [Các loại thông báo](#2-các-loại-thông-báo)
3. [API Contract (Backend)](#3-api-contract-backend)
4. [Frontend — Composable `useNotifications`](#4-frontend--composable-usenotifications)
5. [Màn hình cài đặt thông báo](#5-màn-hình-cài-đặt-thông-báo)
6. [Luồng xin quyền Push](#6-luồng-xin-quyền-push)
7. [Email re-engagement](#7-email-re-engagement)
8. [Danh sách file cần tạo / sửa](#8-danh-sách-file-cần-tạo--sửa)
9. [Checklist tổng](#9-checklist-tổng)

---

## 1. Tổng quan kiến trúc

```
[PWA Frontend]                    [Backend API]                 [FCM / APNs]
      │                                 │                             │
      │── subscribe (FCM token) ───────▶│                             │
      │                                 │── push payload ────────────▶│──▶ iOS / Android
      │                                 │                             │
      │── PUT /notifications/settings ─▶│ (lưu preferences)           │
      │                                 │                             │
      │                        [Scheduler / Celery Beat]              │
      │                                 │── cron: thông báo đầu ngày  │
      │                                 │── cron: thông báo cuối ngày │
      │                                 │── cron: thông báo giữa ngày │
      │                                 │── cron: email re-engage      │
```

**Công nghệ:**
- **Push notifications:** Firebase Cloud Messaging (FCM) — hỗ trợ cả Android lẫn iOS (qua APNs gateway)
- **Service Worker:** `firebase-messaging-sw.js` đăng ký FCM, nhận background push
- **Backend push:** Python `firebase-admin` SDK gửi qua FCM
- **Email:** SMTP hoặc SendGrid cho re-engagement email
- **Scheduler:** Celery Beat (hoặc cron job) chạy theo timezone của từng user

**Lưu ý iOS:**
- iOS yêu cầu PWA được cài về Home Screen mới nhận push
- Safari 16.4+ hỗ trợ Web Push API — cần VAPID key + manifest đúng chuẩn
- FCM trên iOS hoạt động qua APNs: cần upload APNs Auth Key trong Firebase console

---

## 2. Các loại thông báo

### 2.1 Thông báo đầu ngày (Morning Reminder)

| Thuộc tính | Chi tiết |
|-----------|---------|
| Mục đích | Nhắc user bắt đầu log bữa ăn buổi sáng / uống nước |
| Tiêu đề | "Chào buổi sáng! ☀️" |
| Nội dung | "Đừng quên log bữa sáng để theo dõi calo hôm nay nhé!" |
| Thời gian mặc định | 07:00 |
| Có thể thay đổi | ✅ User chọn giờ + phút |
| Bật / Tắt | ✅ |
| Deep link | Mở trang `/log` (log food) |

### 2.2 Thông báo giữa ngày (Midday Reminder)

| Thuộc tính | Chi tiết |
|-----------|---------|
| Mục đích | Nhắc user log bữa trưa + hiển thị calo còn thiếu |
| Tiêu đề | "Nhắc nhở buổi trưa 🍱" |
| Nội dung | "Bạn còn thiếu **{remaining_kcal} kcal** để đạt mục tiêu hôm nay. Hãy log bữa trưa!" |
| Thời gian mặc định | 12:00 (cố định, không đổi được) |
| Có thể thay đổi | ❌ |
| Bật / Tắt | ✅ |
| Dữ liệu động | `remaining_kcal = daily_goal - consumed_today` tính tại thời điểm gửi |
| Deep link | Mở trang `/log` |

### 2.3 Thông báo cuối ngày (Evening Summary)

| Thuộc tính | Chi tiết |
|-----------|---------|
| Mục đích | Tóm tắt ngày + nhắc log bữa tối nếu chưa đủ |
| Tiêu đề | "Tổng kết hôm nay 🌙" |
| Nội dung | "Bạn đã nạp {consumed_kcal}/{daily_goal} kcal. {Còn thiếu X kcal / Đã đạt mục tiêu! 🎉}" |
| Thời gian mặc định | 20:00 |
| Có thể thay đổi | ✅ User chọn giờ + phút |
| Bật / Tắt | ✅ |
| Dữ liệu động | `consumed_kcal`, `daily_goal` tính tại thời điểm gửi |
| Deep link | Mở trang `/summary` (tổng kết ngày) |

### 2.4 Email Re-engagement

| Thuộc tính | Chi tiết |
|-----------|---------|
| Điều kiện kích hoạt | User không mở app trong **7 ngày liên tiếp** |
| Chủ đề email | "Chúng tôi nhớ bạn! 😊 Quay lại theo dõi sức khoẻ nào" |
| Nội dung email | Xem mẫu bên dưới |
| Tần suất gửi | Chỉ gửi **1 lần** mỗi lần user inactive 7 ngày (không spam) |
| Có thể tắt | ✅ Trong cài đặt email preferences |

**Mẫu nội dung email:**
```
Chào {user_name},

Đã 7 ngày rồi bạn chưa ghé thăm CaloEye. Hành trình sức khoẻ của bạn vẫn đang chờ! 💪

📊 Mục tiêu của bạn: {daily_goal} kcal / ngày
🎯 Streak cao nhất: {best_streak} ngày

Hãy quay lại và tiếp tục nhé!

[Mở CaloEye →] {app_url}

---
Để tắt email này, vào Cài đặt → Thông báo → Email.
```

---

## 3. API Contract (Backend)

### 3.1 POST `/notifications/subscribe`
Đăng ký FCM token sau khi user cho phép push.

```json
// Request
{
  "fcm_token": "dq3r9...",
  "device_type": "ios" | "android" | "web"
}

// Response 200
{ "message": "Đăng ký thành công" }

// Response 400
{ "detail": "Token không hợp lệ" }
```

### 3.2 DELETE `/notifications/subscribe`
Hủy đăng ký khi user tắt thông báo hoặc logout.

```json
// Request
{ "fcm_token": "dq3r9..." }

// Response 204 — No Content
```

### 3.3 GET `/notifications/settings`
Lấy cài đặt thông báo hiện tại của user.

```json
// Response 200
{
  "morning": {
    "enabled": true,
    "time": "07:00"         // HH:MM, timezone user
  },
  "midday": {
    "enabled": true
  },
  "evening": {
    "enabled": false,
    "time": "20:00"
  },
  "email_reengagement": {
    "enabled": true
  }
}
```

### 3.4 PUT `/notifications/settings`
Cập nhật cài đặt thông báo.

```json
// Request — gửi chỉ các field cần thay đổi
{
  "morning": { "enabled": true, "time": "06:30" },
  "evening": { "enabled": false }
}

// Response 200
{ "message": "Cập nhật thành công" }

// Response 422
{ "detail": [{ "loc": ["body","morning","time"], "msg": "invalid time format, expected HH:MM" }] }
```

### 3.5 POST `/notifications/test` *(chỉ dùng trong dev)*
Gửi thử một loại thông báo để test.

```json
// Request
{ "type": "morning" | "midday" | "evening" }

// Response 200
{ "message": "Đã gửi thông báo test" }
```

---

## 4. Frontend — Composable `useNotifications`

**File:** `composables/useNotifications.ts`

```typescript
interface NotificationSettings {
  morning: { enabled: boolean; time: string }
  midday: { enabled: boolean }
  evening: { enabled: boolean; time: string }
  email_reengagement: { enabled: boolean }
}

const useNotifications = () => {
  // State
  const settings = ref<NotificationSettings | null>(null)
  const pushPermission = ref<NotificationPermission>('default')
  const fcmToken = ref<string | null>(null)

  // Xin quyền push + lấy FCM token
  const requestPermission = async (): Promise<boolean>

  // Đăng ký token lên backend
  const subscribePush = async (token: string): Promise<void>

  // Hủy đăng ký (gọi khi logout)
  const unsubscribePush = async (): Promise<void>

  // Lấy cài đặt từ server
  const fetchSettings = async (): Promise<void>

  // Lưu cài đặt
  const updateSettings = async (patch: Partial<NotificationSettings>): Promise<void>

  return {
    settings, pushPermission, fcmToken,
    requestPermission, subscribePush, unsubscribePush,
    fetchSettings, updateSettings,
  }
}
```

**Luồng khởi động (gọi trong `app.vue` hoặc layout):**
1. Kiểm tra `Notification.permission`
2. Nếu `granted` → lấy FCM token, gọi `subscribePush`
3. Gọi `fetchSettings` để load preferences

---

## 5. Màn hình cài đặt thông báo

**Route:** `/settings/notifications`  
**File:** `pages/settings/notifications.vue`

```
┌─────────────────────────────────┐
│  ← Thông báo                   │
├─────────────────────────────────┤
│  Push Notifications             │
│  ┌──────────────────────────┐   │
│  │ [Cho phép thông báo]     │   │  ← hiện nếu chưa xin quyền
│  └──────────────────────────┘   │
├─────────────────────────────────┤
│  NHẮC NHỞ HÀNG NGÀY            │
│                                 │
│  Đầu ngày          [●──] BẬT   │
│  Thời gian         07:00  >    │  ← mở time picker
│                                 │
│  Giữa ngày         [●──] BẬT   │
│  (Thời gian cố định: 12:00)     │
│                                 │
│  Cuối ngày         [──●] TẮT   │
│  Thời gian         20:00  >    │
│                                 │
├─────────────────────────────────┤
│  EMAIL                          │
│                                 │
│  Nhắc quay lại (7 ngày)        │
│                    [●──] BẬT   │
└─────────────────────────────────┘
```

**UX notes:**
- Toggle bật/tắt tự động gọi `updateSettings` (debounce 500ms)
- Time picker dùng native `<input type="time">` bọc trong bottom sheet iOS-style
- Nếu `pushPermission !== 'granted'`, ẩn phần nhắc nhở hàng ngày và hiện banner "Cho phép thông báo"
- Nếu user deny permission, hiện hướng dẫn vào Settings hệ thống

---

## 6. Luồng xin quyền Push

```
User vào màn hình cài đặt thông báo
          │
          ▼
  permission === 'default'?
          │ Yes
          ▼
  Hiện dialog giải thích WHY
  (trước khi browser hỏi)
          │
          ▼
  requestPermission() → browser native prompt
          │
    ┌─────┴─────┐
   Yes          No
    │            │
    ▼            ▼
  Lấy FCM   Hiện banner
  token →   "Vào Settings
  subscribe  để bật lại"
  backend
```

**Thời điểm tốt để xin quyền:**
- Không xin ngay khi mở app lần đầu
- Xin sau khi user log xong bữa ăn đầu tiên (có context "muốn theo dõi")
- Hoặc khi user chủ động vào Settings → Thông báo

---

## 7. Email Re-engagement

**Backend job:** Chạy mỗi ngày lúc 09:00 UTC

```python
# Pseudocode Celery task
@celery.task
def send_reengagement_emails():
    cutoff = datetime.utcnow() - timedelta(days=7)
    inactive_users = db.query(User).filter(
        User.last_seen < cutoff,
        User.email_reengagement_enabled == True,
        User.reengagement_sent_at < cutoff  # không gửi trùng
    ).all()

    for user in inactive_users:
        send_email(
            to=user.email,
            template="reengagement",
            context={
                "user_name": user.name,
                "daily_goal": user.daily_goal_kcal,
                "best_streak": user.best_streak,
                "app_url": settings.APP_URL,
            }
        )
        user.reengagement_sent_at = datetime.utcnow()

    db.commit()
```

**Tracking `last_seen`:** Backend cập nhật `users.last_seen` mỗi khi nhận request có `Authorization` header hợp lệ (middleware).

---

## 8. Danh sách file cần tạo / sửa

### Frontend

| File | Tác vụ |
|------|--------|
| `public/firebase-messaging-sw.js` | Service Worker nhận background push |
| `plugins/firebase.client.ts` | Khởi tạo Firebase app + messaging |
| `composables/useNotifications.ts` | Logic push subscribe + settings |
| `pages/settings/notifications.vue` | UI cài đặt thông báo |
| `components/settings/TimePickerSheet.vue` | Bottom sheet chọn giờ |
| `components/notifications/PermissionBanner.vue` | Banner xin quyền |
| `.env` | Thêm `NUXT_PUBLIC_FIREBASE_*` keys |
| `nuxt.config.ts` | Khai báo env + PWA manifest `gcm_sender_id` |

### Backend

| File | Tác vụ |
|------|--------|
| `models/user_notification.py` | Model lưu FCM tokens + settings |
| `routers/notifications.py` | Các endpoint subscribe / settings |
| `tasks/push_notifications.py` | Celery tasks gửi push hàng ngày |
| `tasks/email_reengagement.py` | Celery task email 7 ngày |
| `templates/email/reengagement.html` | Template email |
| `middleware/last_seen.py` | Cập nhật `last_seen` mỗi request |
| `celery_beat_schedule.py` | Đăng ký cron schedule |

---

## 9. Checklist tổng

### Phase 1 — Hạ tầng Push

- [x] Tạo Firebase project, bật Cloud Messaging
- [ ] Upload APNs Auth Key vào Firebase (cho iOS) *(thủ công — cần Apple Developer account)*
- [x] Thêm Firebase config + VAPID key vào `.env`
- [x] Cài `firebase` + workbox packages (`npm install`)
- [x] Tạo `resources/js/plugins/firebase.ts` (khởi tạo Firebase app + messaging)
- [x] Tạo `resources/js/sw.ts` (custom SW: Workbox precaching + FCM background push)
- [x] Cập nhật `vite.config.js` → `injectManifest` strategy + `gcm_sender_id` manifest
- [x] Thêm `VITE_FIREBASE_*` vào `.env.example`
- [x] Test nhận push trên iOS PWA ✅

### Phase 2 — Backend API

- [x] Migration: thêm notification fields vào `users` + tạo bảng `notification_subscriptions`
- [x] Model `NotificationSubscription`
- [x] Cập nhật `User` model (fillable, casts, relation)
- [x] `POST /notifications/subscribe`
- [x] `DELETE /notifications/subscribe`
- [x] `GET /notifications/settings`
- [x] `PUT /notifications/settings`
- [x] Middleware `UpdateLastSeen` (terminate — không làm chậm request)

### Phase 3 — Frontend Settings UI

- [x] Composable `useNotifications` (fetchSettings, updateSetting, requestPermission, initPush, unsubscribe)
- [x] Trang `/settings/notifications` (toggle + native time picker)
- [x] Component `PermissionBanner` (hiện trên Home, dismiss vào localStorage)
- [x] `initPush` gọi trong App.vue — tự subscribe nếu permission đã granted
- [x] Profile.vue → navigation row → `/settings/notifications`

### Phase 4 — Scheduler (Laravel)

- [x] `app/Console/Commands/Notifications/SendMorningNotifications.php` — `notify:morning`, filter theo giờ từng user
- [x] `app/Console/Commands/Notifications/SendMiddayNotifications.php` — `notify:midday`, tính `remaining_kcal`
- [x] `app/Console/Commands/Notifications/SendEveningNotifications.php` — `notify:evening`, tính `consumed_kcal`
- [x] `app/Console/Commands/Notifications/SendReengagementEmails.php` — `notify:reengagement`, throttle 7 ngày
- [x] `app/Mail/ReengagementMail.php` + `resources/views/emails/reengagement.blade.php`
- [x] `app/Services/FcmService.php` — gửi push qua kreait/laravel-firebase
- [x] `routes/console.php` — morning/evening mỗi phút, midday 12:00, reengagement 09:00
- [x] `composer.json` — thêm `kreait/laravel-firebase`
- [x] `config/firebase.php` — published (đọc `FIREBASE_CREDENTIALS` từ env)
- [x] Migrations: `notification_fields_to_users`, `notification_subscriptions`, `reengagement_sent_at`
- [x] `docker-compose.yml` + `docker-compose.prod.yml` — thêm `scheduler` + `queue` services
- [x] Middleware `UpdateLastSeen` — cập nhật `last_seen_at` sau mỗi request (terminate)
- [x] `POST /notifications/test` — endpoint test push (chỉ local/staging)
- [x] Unsubscribe FCM token khi logout

### Phase 5 — iOS APNs (cần Apple Developer account)

> ⚠️ Bước này cần thực hiện thủ công, không thể tự động hoá.

#### 5.1 Upload APNs Auth Key lên Firebase

1. Vào [Apple Developer](https://developer.apple.com) → **Certificates, Identifiers & Profiles** → **Keys**
2. Tạo key mới, bật **Apple Push Notifications service (APNs)**
3. Download file `.p8` + ghi nhớ **Key ID** và **Team ID**
4. Vào [Firebase Console](https://console.firebase.google.com) → **Project Settings** → **Cloud Messaging**
5. Cuộn xuống **Apple app configuration** → **Upload** APNs Auth Key (.p8)
6. Nhập Key ID và Team ID

#### 5.2 Cài Firebase Credentials cho backend

1. Firebase Console → **Project Settings** → **Service accounts**
2. Click **Generate new private key** → download JSON
3. Đặt file JSON vào server (vd: `/var/www/firebase-credentials.json`)
4. Thêm vào `.env`:
   ```
   FIREBASE_CREDENTIALS=/var/www/firebase-credentials.json
   ```
5. Hoặc paste nội dung JSON trực tiếp vào env var (single line):
   ```
   FIREBASE_CREDENTIALS={"type":"service_account","project_id":"app-pt-5464c",...}
   ```

#### 5.3 Khởi động scheduler + queue (dev)

```bash
# Khởi động tất cả services (bao gồm scheduler và queue)
docker compose up -d

# Kiểm tra scheduler đang chạy
docker compose logs scheduler -f

# Test gửi push thủ công
docker exec app-pt-client-backend-1 php artisan notify:morning
```

#### 5.4 Checklist iOS PWA

- [ ] Upload APNs Auth Key lên Firebase *(thủ công — cần Apple Developer account)*
- [ ] Set `FIREBASE_CREDENTIALS` trong `.env` trên server
- [ ] Khởi động lại Docker với `scheduler` + `queue` services
- [x] Safari 16.4+ trên iOS ✅ (đã test với ngrok)
- [x] Manifest có `gcm_sender_id` ✅
- [x] Add to Home Screen để nhận push ✅
- [ ] Test thực tế: `POST /api/v1/notifications/test` với `{"type":"morning"}`
