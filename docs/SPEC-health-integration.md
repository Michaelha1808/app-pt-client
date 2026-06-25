# SPEC — Tích hợp App Sức khoẻ (kiểu Strava)

> Trạng thái: Draft · Phạm vi MVP: **Strava** · Mở rộng sau: **Fitbit / Garmin**
> Mục tiêu: Kéo dữ liệu buổi tập từ provider bên ngoài về CaloEye để (1) cộng **calo đốt** vào ngày, (2) đóng góp **streak**, (3) hiển thị **feed hoạt động**.

---

## 1. Bối cảnh & ràng buộc

- Backend: **Laravel + Sanctum** (token bearer + refresh cookie), API versioned tại `routes/api_v1.php`.
- Frontend: **Vue 3 PWA** (không có native shell).
- Đã có pattern OAuth qua **Socialite** (Google/Facebook) — nhưng đó là OAuth *đăng nhập*. Tích hợp này là OAuth *cấp quyền đọc dữ liệu* (scope khác, lưu token để gọi API lâu dài).
- Streak hiện tại: `StreakService::recordMealActivity()` cộng chuỗi khi log bữa ăn. Sẽ tổng quát hoá để buổi tập cũng tính.

### Ràng buộc quan trọng (đã chốt)
| Provider | Có cloud API? | Khả thi với PWA | Ghi chú |
|---|---|---|---|
| **Strava** | ✅ OAuth2 + Webhook | ✅ **MVP** | Token hết hạn mỗi ~6h, phải refresh |
| **Fitbit / Garmin** | ✅ OAuth2 | ✅ Phase sau | Kiến trúc gần giống Strava |
| Apple Health | ❌ Không có server API | ❌ | Cần app native (HealthKit) — ngoài scope PWA |
| Google Fit | ⚠️ REST đang deprecate | ❌ | Google đẩy sang Health Connect (native) |

→ Thiết kế theo **provider-agnostic** (interface chung) để thêm Fitbit/Garmin không phải sửa core.

---

## 2. Kiến trúc tổng thể

```
[Vue PWA]                    [Laravel API]                     [Strava]
   │  1. bấm "Kết nối Strava"     │                                │
   │ ───────────────────────────► │  GET /integrations/strava/connect
   │  2. redirect tới Strava ◄──── │ ──────── authorize URL ──────► │
   │  3. user đồng ý ─────────────────────────────────────────────►│
   │                              │ ◄──── callback ?code=... ───────│
   │                              │  exchange code → access+refresh token
   │                              │  lưu HealthConnection (token mã hoá)
   │  ◄──── redirect về app ───── │  + tạo webhook subscription
   │                              │                                │
   │                              │ ◄═══ POST webhook (activity mới) ═══
   │                              │  dispatch SyncActivityJob
   │                              │  fetch chi tiết → lưu HealthActivity
   │                              │  → cộng calo + cập nhật streak
   │  GET /integrations/activities│                                │
   │ ◄─── feed + calo đốt ─────── │                                │
```

Hai luồng đồng bộ:
- **Webhook (real-time)**: Strava push khi có activity mới → job fetch chi tiết.
- **Backfill / fallback poll**: khi mới kết nối, kéo N activity gần nhất; cron quét định kỳ phòng webhook miss.

---

## 3. Data model (migrations)

Theo convention hiện có (`database/migrations/2026_06_24_xxxxxx_*`).

### 3.1 `health_connections`
Một user có thể nối nhiều provider.
```
id
user_id            FK → users, index
provider           enum: strava | fitbit | garmin
provider_user_id   string  (athlete id bên provider)
access_token       text    (ENCRYPTED)
refresh_token      text    (ENCRYPTED, nullable)
token_expires_at   timestamp nullable
scopes             string  (vd: "read,activity:read_all")
last_synced_at     timestamp nullable
webhook_id         string nullable   (id subscription bên provider)
status             enum: active | revoked | error
created_at / updated_at
unique(user_id, provider)
```

### 3.2 `health_activities`
```
id
user_id              FK → users, index
health_connection_id FK
provider             string
external_id          string   (activity id bên provider)
type                 string   (run / ride / swim / workout ...)
name                 string nullable
started_at           timestamp, index
duration_seconds     integer
distance_meters      integer nullable
calories             integer nullable   ← dùng cộng vào ngày
raw                  json     (payload gốc để mở rộng sau)
created_at / updated_at
unique(provider, external_id)
```

> **Bảo mật token**: dùng cast `'encrypted'` của Laravel cho `access_token`/`refresh_token` (tự mã hoá bằng `APP_KEY`). Không bao giờ trả token ra API.

---

## 4. Backend — thành phần

### 4.1 Provider abstraction
```
app/Services/Health/
  HealthProvider.php          (interface)
  StravaProvider.php          (implements)
  HealthProviderFactory.php   (resolve theo tên provider)
```
Interface tối thiểu:
```php
interface HealthProvider {
    public function authorizeUrl(string $state): string;
    public function exchangeCode(string $code): TokenSet;      // access+refresh+expiry+athleteId
    public function refresh(string $refreshToken): TokenSet;
    public function fetchActivity(HealthConnection $c, string $externalId): array;
    public function fetchRecentActivities(HealthConnection $c, int $limit): array;
    public function registerWebhook(): string;                 // trả webhook_id
    public function verifyWebhook(Request $r): bool;
}
```

### 4.2 Controller — `Api/V1/IntegrationController.php`
| Method | Route | Mô tả |
|---|---|---|
| `connect` | `GET /integrations/{provider}/connect` | Trả/redirect authorize URL (state = user + redirect_uri đã ký) |
| `callback` | `GET /integrations/{provider}/callback` | Đổi `code` → token, lưu connection, đăng ký webhook, redirect về app |
| `index` | `GET /integrations` | Danh sách provider đã kết nối + trạng thái |
| `disconnect` | `DELETE /integrations/{provider}` | Revoke token + xoá webhook + đánh dấu `revoked` |
| `activities` | `GET /integrations/activities` | Feed activity (phân trang) |

### 4.3 Webhook — `Api/V1/IntegrationWebhookController.php` (route **public**, không Sanctum)
| Method | Route | Mô tả |
|---|---|---|
| `verify` | `GET /webhooks/strava` | Strava challenge handshake (echo `hub.challenge`) |
| `receive` | `POST /webhooks/strava` | Nhận event → `dispatch(SyncActivityJob)` → trả 200 ngay (≤2s) |

> Webhook **không** xử lý đồng bộ trong request — chỉ enqueue job (Strava yêu cầu phản hồi nhanh).

### 4.4 Jobs (queue)
- `SyncActivityJob(connection, externalId)` — fetch chi tiết 1 activity → upsert `health_activities` → gọi `HealthStreakService`/cộng calo. Idempotent qua `unique(provider, external_id)`.
- `BackfillActivitiesJob(connection)` — chạy sau khi kết nối lần đầu, kéo N activity gần nhất.
- `RefreshExpiringTokensJob` — cron, refresh token sắp hết hạn.
- (cron) `PollActivitiesCommand` — fallback quét provider mỗi X giờ phòng webhook miss.

### 4.5 Tích hợp nghiệp vụ
- **Calo đốt (ĐÃ CHỐT — trừ vào goal)**: dùng mô hình **Net calories**.
  - Công thức ring: `net = tổng calo nạp − tổng calo đốt`, so với `calorie_goal`. Đốt nhiều → net giảm → còn budget để nạp thêm.
  - (Tương đương về toán: `goal hiển thị = calorie_goal + calo đốt`. Chọn cách net để khớp `CalorieRing` hiện tại, chỉ đổi tử số.)
  - Endpoint `GET /food/today` (`FoodController@todayStats`) cần trả thêm `calories_burned` (tổng `health_activities.calories` trong ngày) để FE tính net.
  - Lưu activity ở `health_activities`; **không** trộn vào `meal_logs`. Calo đốt là field tổng hợp theo ngày, không phải bữa ăn.
  - Lưu ý hiển thị: nếu `net < 0` (đốt > nạp), ring không vượt 0 — show "còn dư X kcal" thay vì âm.
- **Streak**: tổng quát hoá `StreakService` — tách `recordMealActivity()` thành `recordActivity(User, date)` để cả buổi tập lẫn bữa ăn cùng đẩy chuỗi (1 ngày có hoạt động bất kỳ = giữ streak). Tránh double-count trong cùng ngày (đã có guard `last_activity_date === today`).
- **Feed**: `GET /integrations/activities` trả danh sách cho trang mới.

---

## 5. Frontend (Vue 3 PWA)

### 5.1 Composable
`resources/js/composables/useHealthIntegration.ts`
- `connections` (state), `connect(provider)`, `disconnect(provider)`, `fetchActivities()`.
- `connect()` mở authorize URL (redirect cả tab, giống flow Google/Facebook hiện tại trong `useAuth`).

### 5.2 UI
- **Settings/Profile**: card "Kết nối ứng dụng sức khoẻ" → nút *Kết nối Strava* / trạng thái *Đã kết nối · Ngắt kết nối*.
- **Trang Activity feed** (mới, vd `pages/Activities.vue`): list buổi tập (icon theo type, quãng đường, thời lượng, calo). Thêm vào `BottomNav` hoặc trong History.
- **CalorieRing**: hiển thị calo đốt (nếu chọn Option A).
- **Callback**: tái dùng `pages/auth/Callback.vue` hoặc route `/integrations/callback` để nuốt redirect và toast kết quả.

### 5.3 Types
`resources/js/types/health.ts` — `HealthConnection`, `HealthActivity`.

---

## 6. Cấu hình & secrets

`config/services.php`:
```php
'strava' => [
    'client_id'     => env('STRAVA_CLIENT_ID'),
    'client_secret' => env('STRAVA_CLIENT_SECRET'),
    'redirect'      => env('STRAVA_REDIRECT_URI'),
    'verify_token'  => env('STRAVA_WEBHOOK_VERIFY_TOKEN'),
],
```
`.env` + **`.env.example`** (mirror keys, để trống — theo quy ước dự án):
```
STRAVA_CLIENT_ID=
STRAVA_CLIENT_SECRET=
STRAVA_REDIRECT_URI=
STRAVA_WEBHOOK_VERIFY_TOKEN=
```

---

## 7. Bảo mật

- Token mã hoá at-rest (`encrypted` cast). Không log, không trả ra response.
- `state` param khi connect phải **ký** (HMAC) hoặc lưu server-side để chống CSRF / nhận diện đúng user.
- Webhook: verify `hub.verify_token` lúc handshake; với event POST, chỉ tin `owner_id` + đối chiếu connection (Strava không ký payload → không xử lý dữ liệu trong payload, luôn *re-fetch* từ API bằng token).
- Rate limit route connect/callback (throttle, giống `throttle:10,1` hiện có).
- Tôn trọng disconnect: gọi endpoint revoke của provider, xoá token local.

---

## 8. Kế hoạch triển khai (phân giai đoạn)

### Phase 0 — Chuẩn bị (½ ngày)
- [ ] Đăng ký Strava API app, lấy client id/secret, set redirect URI.
- [ ] Thêm config + env keys (+ `.env.example`).

### Phase 1 — Connect flow (1–2 ngày)
- [ ] Migration `health_connections`.
- [ ] `HealthProvider` interface + `StravaProvider` (authorizeUrl, exchangeCode, refresh).
- [ ] `IntegrationController@connect/@callback/@index/@disconnect` + routes.
- [ ] FE: card kết nối trong Settings + composable `connect/disconnect`.
- [ ] **Mốc kiểm thử**: kết nối được, thấy "Đã kết nối", token lưu mã hoá.

### Phase 2 — Đồng bộ activity (2–3 ngày)
- [ ] Migration `health_activities`.
- [ ] Webhook controller (verify + receive) + route public.
- [ ] `SyncActivityJob` + `BackfillActivitiesJob` (queue).
- [ ] `RefreshExpiringTokensJob` + đăng ký vào scheduler (`routes/console.php`).
- [ ] **Mốc kiểm thử**: tạo activity trên Strava → tự xuất hiện trong DB.

### Phase 3 — Nghiệp vụ + UI (2–3 ngày)
- [ ] Cộng calo đốt (Net calories) + cập nhật CalorieRing.
- [ ] Tổng quát hoá `StreakService` để buổi tập tính streak.
- [ ] Trang feed activity + endpoint `GET /integrations/activities`.
- [ ] (tuỳ chọn) Push FCM khi sync activity mới (tái dùng `FcmService`).

### Phase 4 — Mở rộng (sau)
- [ ] `FitbitProvider` / `GarminProvider` qua cùng interface.
- [ ] Poll fallback command.

---

## 9. Quyết định đã chốt

1. **Calo đốt trừ vào goal** → mô hình Net calories (§4.5).
2. **Quyền Strava** → `activity:read` (không xin private cho MVP; nâng `activity:read_all` sau nếu user cần).
3. **Backfill** → kéo **7 ngày** gần nhất khi kết nối lần đầu (tránh rate limit & không phá streak lịch sử). Sau đó webhook lo real-time.
4. **Queue driver** → `database` (đã có bảng `jobs`, không cần Redis). Cần chạy `php artisan queue:work`/Supervisor trên server.
5. **Dedup** → chỉ dựa `unique(provider, external_id)`. Không dedup chéo nguồn ở MVP (để Phase 4).

### Lưu ý môi trường dev (Laragon / localhost)
- Strava **webhook cần URL public HTTPS** để đăng ký subscription. Trên localhost phải dùng **ngrok** (hoặc tunnel khác): `ngrok http 80` → lấy URL `https://xxx.ngrok.io` đặt vào callback/webhook khi test.
- Strava **rate limit**: ~100 request/15 phút và 1000/ngày — lưu ý khi backfill và khi test lặp lại.

---

## 10. Tham chiếu code hiện có để tái dùng

- OAuth redirect/callback pattern: `app/Http/Controllers/Api/V1/AuthController.php` (Socialite Google/FB).
- Streak: `app/Services/StreakService.php`, `app/Models/UserStreak.php`.
- Push: `app/Services/FcmService.php`.
- Routes versioned: `routes/api_v1.php`.
- Migration convention: `database/migrations/2026_06_24_*`.
- FE auth/OAuth flow: `resources/js/composables/useAuth.ts`, `pages/auth/Callback.vue`.
```
