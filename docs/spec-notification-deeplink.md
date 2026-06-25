# Spec: Notification Deep-Link — Chạm thông báo mở đúng màn hình (kèm redirect qua đăng nhập)

> **App:** CaloEye — Vue 3 SPA + Laravel 13 + Tailwind CSS 4 (iOS-style PWA)
> **Cập nhật lần cuối:** 2026-06-25
> **Trạng thái tổng:** 🟢 Phase 1 + Phase 2 hoàn thành
> **Liên quan:** hệ thống push đã có ([`spec-notifications.md`](spec-notifications.md)) — FCM + Service Worker

---

## Mục lục
1. [Mục tiêu & hiện trạng](#1-mục-tiêu--hiện-trạng)
2. [Bản đồ thông báo → màn hình](#2-bản-đồ-thông-báo--màn-hình)
3. [Payload contract](#3-payload-contract)
4. [Service Worker — notificationclick](#4-service-worker--notificationclick)
5. [Client — nhận lệnh điều hướng](#5-client--nhận-lệnh-điều-hướng)
6. [Redirect qua đăng nhập (yêu cầu cốt lõi)](#6-redirect-qua-đăng-nhập-yêu-cầu-cốt-lõi)
7. [Foreground (app đang mở)](#7-foreground-app-đang-mở)
8. [Deep-link trong Notification Panel (in-app)](#8-deep-link-trong-notification-panel-in-app)
9. [Các kịch bản đầy đủ](#9-các-kịch-bản-đầy-đủ)
10. [Danh sách file cần tạo / sửa](#10-danh-sách-file-cần-tạo--sửa)
11. [Checklist tổng](#11-checklist-tổng)
12. [Edge cases & notes](#12-edge-cases--notes)

---

## 1. Mục tiêu & hiện trạng

**Yêu cầu:** Khi thiết bị hiện thông báo đẩy, người dùng chạm vào → mở app **đúng màn hình/chức năng** mà thông báo nói tới. **Kể cả khi màn đó bắt đăng nhập**, sau khi đăng nhập xong app vẫn **tự mở đúng màn đó** (không rơi về /home).

### Hiện trạng (đã có)
- ✅ Backend gửi `data: { url: '...' }` theo từng loại noti (xem §2).
- ✅ SW `onBackgroundMessage` hiển thị notification kèm `data` (`resources/js/sw.ts`).
- ⚠️ SW `notificationclick` có sẵn nhưng **lỗi focus**: so `client.url === url` (URL đầy đủ vs path `/scan`) gần như không khớp → **mở tab/cửa sổ mới** mỗi lần thay vì focus PWA đang mở + điều hướng.
- ❌ Foreground (`onMessage` trong `useNotifications`): chỉ `new Notification(...)`, **không gắn data, không xử lý click** → chạm không đi đâu.
- ❌ **Không có** cơ chế lưu đích đến để **mở lại sau khi đăng nhập**.
- ❌ Notification Panel (chuông in-app): item lịch sử **không có url** để điều hướng (`notification_logs` chỉ lưu type/title/body).

### Phạm vi
- **Phase 1:** Sửa SW focus + điều hướng SPA; client message listener; **redirect qua login** (cốt lõi); foreground click.
- **Phase 2:** Deep-link trong Notification Panel (thêm cột `url` cho `notification_logs`).

---

## 2. Bản đồ thông báo → màn hình

| Loại (`type`) | Nguồn | `data.url` hiện tại | Ghi chú |
|---------------|-------|---------------------|---------|
| `morning` | SendMorningNotifications | `/scan` | Nhắc ghi bữa sáng |
| `midday` | SendMiddayNotifications | `/scan` | + `remaining_kcal` |
| `evening` | SendEveningNotifications | `/history` | Xem lại ngày |
| `streak_risk` | SendStreakRiskReminders | `/home` | Chuỗi sắp mất |
| `streak_freeze_remind` | SendFreezeSuggestions | `/home` + `action:freeze` | Gợi ý dùng freeze |
| `test` | NotificationController@sendTest | `/home` | Test |
| `meal_plan` *(mới, tùy chọn)* | (job tương lai) | `/plan` | Nhắc xem kế hoạch |

> URL là **path nội bộ** của SPA (vue-router), không phải URL tuyệt đối. Mọi loại đã có `url` — chỉ cần client xử lý đúng.

---

## 3. Payload contract

FCM `data` (string→string, theo yêu cầu của FCM):

```json
{
  "url":  "/history",          // path đích trong app (bắt buộc)
  "type": "evening",           // loại noti (để client xử lý đặc thù, vd mở modal)
  "action": "freeze"           // optional: hành động phụ (vd mở StreakModal)
}
```

**Quy ước:**
- `url` luôn là path bắt đầu bằng `/`. Whitelist path hợp lệ ở client (tránh open-redirect tới URL ngoài).
- `type`/`action` optional, dùng cho xử lý nâng cao (mở modal, scroll tới phần tử…).
- Notification (hiển thị) vẫn ở `notification: { title, body }` như hiện tại.

---

## 4. Service Worker — notificationclick

**File:** `resources/js/sw.ts` (sửa handler hiện có)

```ts
const ALLOWED_PREFIXES = ['/home', '/scan', '/history', '/plan', '/chat', '/profile', '/result', '/meal-picker']

function safePath(raw?: string): string {
  if (!raw || !raw.startsWith('/')) return '/home'
  return ALLOWED_PREFIXES.some(p => raw === p || raw.startsWith(p + '?') || raw.startsWith(p + '/'))
    ? raw : '/home'
}

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const data = (event.notification.data ?? {}) as Record<string, string>
  const path = safePath(data.url)

  event.waitUntil((async () => {
    const allClients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })

    // 1) Có cửa sổ PWA đang mở (cùng origin) → focus + nhờ SPA điều hướng client-side
    for (const client of allClients) {
      if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
        await client.focus()
        client.postMessage({ source: 'caloeye-sw', type: 'NAVIGATE', path, data })
        return
      }
    }

    // 2) Chưa mở → mở cửa sổ mới thẳng vào path (cold start)
    if (self.clients.openWindow) await self.clients.openWindow(path)
  })())
})
```

**Điểm sửa chính so với hiện tại:**
- So sánh theo **origin** thay vì URL đầy đủ → luôn focus được PWA đang mở.
- **postMessage** path cho client để vue-router điều hướng **không reload** (giữ state, mượt).
- Whitelist path chống điều hướng rác.

---

## 5. Client — nhận lệnh điều hướng

**File mới:** `resources/js/plugins/notificationNav.ts` (gọi trong `app.ts` sau khi mount router)

```ts
import { router } from '@/router'
import { goWithAuth } from '@/utils/deeplink'

export function initNotificationNav() {
  if (!('serviceWorker' in navigator)) return
  navigator.serviceWorker.addEventListener('message', (event) => {
    const msg = event.data
    if (msg?.source === 'caloeye-sw' && msg.type === 'NAVIGATE' && typeof msg.path === 'string') {
      goWithAuth(msg.path)        // điều hướng (tự xử lý auth + pending)
    }
  })
}
```

**Helper điều hướng:** `resources/js/utils/deeplink.ts`
```ts
import { router } from '@/router'
export function goWithAuth(path: string) {
  // Chỉ cần push — router guard sẽ tự lưu pending_redirect nếu route cần auth (xem §6)
  router.push(path)
}
```

> Với **cold start** (mở cửa sổ mới vào `/plan`): không cần postMessage — bản thân URL đã là đích. Router guard chạy bình thường và xử lý auth như §6.

---

## 6. Redirect qua đăng nhập (yêu cầu cốt lõi)

Khi đích đến cần đăng nhập mà user chưa đăng nhập → lưu đích → ép login → **sau khi login tự mở lại đích**.

### 6.1 Lưu đích trong Router Guard

**File:** `resources/js/router/index.ts` (sửa `beforeEach`)

```ts
const PENDING_KEY = 'pending_redirect'

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!sessionInitialized) { sessionInitialized = true; await restoreSession() }

  const mw = to.meta.middleware as string | undefined
  const needAuth =
    (mw === 'auth' && !auth.isLoggedIn && !auth.isGuest) ||
    (mw === 'auth-strict' && !auth.isLoggedIn)

  if (mw === 'guest' && auth.isLoggedIn) return '/home'

  if (needAuth) {
    // Lưu đích đang muốn vào (trừ trang auth) để mở lại sau khi đăng nhập
    if (!to.path.startsWith('/auth')) {
      sessionStorage.setItem(PENDING_KEY, to.fullPath)
    }
    return '/auth/login'
  }
})
```

### 6.2 Tiêu thụ đích sau khi đăng nhập

**File:** `resources/js/composables/useAuth.ts` — sau `login()` / `register()` / `handleOAuthCallback()` thành công, thay `router.push('/home')` bằng:

```ts
function consumePendingRedirect(): string {
  const dest = sessionStorage.getItem('pending_redirect')
  sessionStorage.removeItem('pending_redirect')
  return dest && !dest.startsWith('/auth') ? dest : '/home'
}
// ... sau khi set user+token:
router.push(consumePendingRedirect())
```

**Lưu ý storage:** dùng `sessionStorage` (theo tab). Cold-start mở cửa sổ mới → guard lưu pending trong chính tab đó → login cùng tab đọc lại được. OAuth round-trip (Google/Facebook) quay lại `/auth/callback` cùng tab nên `sessionStorage` vẫn còn.

### 6.3 Guest đang ở chế độ khách
- Nếu route đích cần **auth-strict** (vd `/plan`, `/history`) mà đang là khách → vẫn lưu pending + ép login (khách = chưa `isLoggedIn`). Sau khi đăng nhập thật → mở đích.

---

## 7. Foreground (app đang mở)

**File:** `resources/js/composables/useNotifications.ts` — trong `onMessage`, gắn click handler điều hướng:

```ts
onMessage(messaging, (payload) => {
  const { title = 'CaloEye', body } = payload.notification ?? {}
  const data = payload.data ?? {}
  if (Notification.permission !== 'granted') return
  const notif = new Notification(title, { body, icon: '/logo/caloreye_icon_192.png', data })
  notif.onclick = () => {
    window.focus()
    goWithAuth(safePath(data.url))   // dùng chung helper + whitelist
    notif.close()
  }
})
```

> Tùy chọn UX tốt hơn: thay `new Notification` bằng **in-app toast** (đã có `useToast`) có nút "Xem" → `goWithAuth`. Để Phase sau nếu muốn.

---

## 8. Deep-link trong Notification Panel (in-app) — Phase 2

Để item trong chuông (lịch sử) cũng chạm-để-mở đúng màn:

### 8.1 Lưu `url` vào `notification_logs`
- **Migration mới:** thêm cột `url` (string, nullable) vào `notification_logs`.
- **Sửa các command** (`SendMorning/Midday/Evening/StreakRisk/FreezeSuggestions` + `sendTest`): truyền `url` vào `NotificationLog::create([... 'url' => '/scan'])` (dùng đúng path đã gửi FCM).
- **Model `NotificationLog`:** thêm `url` vào `#[Fillable]`.
- **`history()` controller:** trả thêm `url`.

### 8.2 Frontend
- `NotificationPanel.vue`: mỗi item bấm → `goWithAuth(item.url ?? '/home')` + đánh dấu đã đọc.

---

## 9. Các kịch bản đầy đủ

| # | Trạng thái app | Đăng nhập? | Route đích | Hành vi mong đợi |
|---|----------------|-----------|-----------|------------------|
| 1 | Đóng hẳn | Đã login | `/scan` | Mở app → vào thẳng `/scan` |
| 2 | Chạy nền | Đã login | `/history` | Focus PWA → điều hướng `/history` (không reload) |
| 3 | Đang mở foreground | Đã login | `/plan` | Chạm noti → `/plan` |
| 4 | Đóng hẳn | **Chưa login** | `/plan` (auth-strict) | Mở `/plan` → guard lưu pending → `/auth/login` → **đăng nhập xong tự vào `/plan`** |
| 5 | Chạy nền | Chế độ khách | `/history` (auth-strict) | Focus → `/history` → guard ép login → đăng nhập → `/history` |
| 6 | — | — | url lạ/độc | Whitelist chặn → fallback `/home` |

---

## 10. Danh sách file cần tạo / sửa

### Tạo mới
| File | Mô tả | Ưu tiên |
|------|--------|---------|
| `resources/js/utils/deeplink.ts` | `goWithAuth()`, `safePath()` (whitelist) | 🔴 P0 |
| `resources/js/plugins/notificationNav.ts` | Lắng nghe SW message → điều hướng | 🔴 P0 |
| `database/migrations/..._add_url_to_notification_logs.php` | Cột `url` | 🟡 P1 (Phase 2) |

### Sửa
| File | Việc | Ưu tiên |
|------|------|---------|
| `resources/js/sw.ts` | Fix `notificationclick`: focus theo origin + postMessage + whitelist | 🔴 P0 |
| `resources/js/router/index.ts` | Guard lưu `pending_redirect` khi ép login | 🔴 P0 |
| `resources/js/composables/useAuth.ts` | `consumePendingRedirect()` sau login/register/OAuth | 🔴 P0 |
| `resources/js/app.ts` | Gọi `initNotificationNav()` | 🔴 P0 |
| `resources/js/composables/useNotifications.ts` | Foreground `onMessage` → click điều hướng | 🔴 P0 |
| `app/Models/NotificationLog.php` | Thêm `url` vào Fillable | 🟡 P1 |
| `app/Console/Commands/Notifications/*` + `NotificationController@sendTest` | Lưu `url` vào NotificationLog | 🟡 P1 |
| `app/Http/Controllers/Api/V1/NotificationController.php` | `history()` trả `url` | 🟡 P1 |
| `resources/js/components/notifications/NotificationPanel.vue` | Item tap → `goWithAuth` | 🟡 P1 |

---

## 11. Checklist tổng

### Phase 1 — Deep-link push + redirect qua login (P0) ✅ HOÀN THÀNH
- [x] Spec viết xong
- [x] `utils/deeplink.ts`: `safePath()` whitelist + `goWithAuth()`
- [x] `sw.ts`: sửa `notificationclick` (focus origin + postMessage + openWindow + whitelist)
- [x] `plugins/notificationNav.ts` + gọi trong `app.ts`
- [x] `router/index.ts`: lưu `pending_redirect` khi ép login
- [x] `useAuth.ts`: `consumePendingRedirect()` cho login/register/OAuth callback
- [x] `useNotifications.ts`: foreground click điều hướng
- [ ] Test 6 kịch bản (§9) — đặc biệt #4 (login xong vào đúng màn) — chờ test thủ công trên thiết bị

### Phase 2 — Deep-link in-app Notification Panel (P1) ✅ HOÀN THÀNH
- [x] Migration thêm `url` cho `notification_logs` + Fillable
- [x] Các command lưu `url` (morning/midday→/scan, evening→/history, streak_risk/freeze→/home)
- [x] `history()` trả `url`
- [x] `markRead` endpoint per-item (`PATCH /notifications/{notificationLog}/read`)
- [x] `NotificationPanel.vue`: tap item → `goWithAuth` + mark read

---

## 12. Edge cases & notes

- **Open-redirect:** luôn whitelist `safePath()` cả ở SW lẫn client — không bao giờ điều hướng tới URL tuyệt đối từ payload.
- **iOS PWA:** `notificationclick` + `openWindow` hoạt động khi PWA đã "Add to Home Screen". Quyền noti cần user gesture (đã xử lý trong `requestPermission`).
- **Cold start cần restoreSession:** guard đã `await restoreSession()` trước khi check auth → tránh ép login nhầm khi token còn hợp lệ (chỉ chưa kịp khôi phục).
- **pending_redirect chỉ dùng 1 lần:** luôn `removeItem` ngay khi tiêu thụ; bỏ qua nếu trỏ vào `/auth/*` (tránh vòng lặp).
- **action/type nâng cao:** vd `action:freeze` → sau khi tới `/home` có thể mở `StreakModal`. Đưa vào Phase sau bằng cách đọc `data.action` trong `goWithAuth` (query param hoặc store tạm).
- **Trùng cửa sổ:** chỉ focus **một** client đầu tiên cùng origin; đủ cho PWA (thường 1 instance).
- **Không reload khi warm:** dùng `router.push` (client-side) thay vì set `location.href` → giữ nguyên state app.

---

*File này được cập nhật mỗi khi hoàn thành một task. Kiểm tra checklist trước khi bắt đầu làm.*
