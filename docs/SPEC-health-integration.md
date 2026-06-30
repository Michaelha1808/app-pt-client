# SPEC — Tích hợp App Sức khoẻ (kiểu Strava)

> Trạng thái: Draft · Phạm vi MVP: **Strava** · Mở rộng sau: **Fitbit / Garmin**
> Mục tiêu: Kéo dữ liệu buổi tập từ provider bên ngoài về CaloEye để (1) cộng **calo đốt** vào ngày, (2) đóng góp **streak**, (3) hiển thị **feed hoạt động**.
>
> **Định vị tính năng:** Strava là **opt-in cho nhóm có thiết bị** (đa số user sẽ KHÔNG có). Đường chính cho số đông là **log buổi tập thủ công** (§4.6). Cả hai nguồn dùng **chung một data model + chung logic net-calories/streak** — Strava chỉ là một "provider" bên cạnh `manual`. User không kết nối gì thì ring chỉ tính calo nạp như hiện tại (calo đốt là lớp nâng cao, hiện khi có dữ liệu).

---

## 1. Bối cảnh & ràng buộc

- Backend: **Laravel + Sanctum** (token bearer + refresh cookie), API versioned tại `routes/api_v1.php`.
- Frontend: **Vue 3 PWA** (không có native shell).
- Đã có pattern OAuth qua **Socialite** (Google/Facebook) — nhưng đó là OAuth *đăng nhập*. Tích hợp này là OAuth *cấp quyền đọc dữ liệu* (scope khác, lưu token để gọi API lâu dài).
- Streak hiện tại: `StreakService::recordMealActivity()` cộng chuỗi khi log bữa ăn. Sẽ tổng quát hoá để buổi tập cũng tính.

### Ràng buộc quan trọng (đã chốt)
| Provider | Có cloud API? | Khả thi với PWA | Ghi chú |
|---|---|---|---|
| **Strava** | ✅ OAuth2 + Webhook | ✅ **MVP** | Token hết hạn mỗi ~6h, phải refresh. ⚠️ **API nay yêu cầu tài khoản Strava trả phí** (subscriber) mới tạo được API app — Free Account bị chặn (xác nhận 2026-06). Đã chốt: sẽ subscribe. |
| **Fitbit / Garmin** | ✅ OAuth2 | ✅ Phase sau | Kiến trúc gần giống Strava |
| Apple Health | ❌ Không có server API | ❌ | Cần app native (HealthKit) — ngoài scope PWA |
| Google Fit | ⚠️ REST đang deprecate | ❌ | Google đẩy sang Health Connect (native) |

→ Thiết kế theo **provider-agnostic** (interface chung) để thêm Fitbit/Garmin không phải sửa core.

> **Đã tìm hiểu Apple Health — chốt LOẠI khỏi mọi phase (kể cả tương lai gần với kiến trúc hiện tại).**
> - HealthKit **không có cloud/REST API**: dữ liệu nằm on-device + iCloud mã hoá đầu-cuối, không server nào query được.
> - Chỉ đọc được qua **native app có entitlement HealthKit**. PWA/Safari **không có JS API** chạm tới HealthKit → chặn cứng với mô hình "PWA không native shell".
> - Mọi hướng đọc Apple Health đều cần **một mảnh native trên thiết bị**: (A) wrapper Capacitor + plugin HealthKit rồi POST lên backend; (B) aggregator Terra/Vital/Spike/Rook — vẫn phải nhúng SDK native, chỉ đỡ phần backend; (C) gián tiếp — để Strava/Fitbit hút workout từ Health rồi mình lấy qua cloud API của họ (chính là hướng MVP này).
> - Kết luận: không có cách đọc Apple Health từ **PWA thuần**. Strava MVP là lựa chọn đúng. Nếu sau này muốn Apple Health thật → phải chấp nhận build native shell (Capacitor), là quyết định kiến trúc riêng, ngoài spec này.

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
health_connection_id FK, nullable        (NULL khi nguồn = manual)
provider             string   (strava | fitbit | garmin | manual)
external_id          string nullable      (activity id bên provider; NULL khi manual)
source               enum: provider | manual   (phân biệt nguồn rõ ràng)
type                 string   (run / ride / swim / workout / walk ...)
name                 string nullable
started_at           timestamp, index
duration_seconds     integer
distance_meters      integer nullable
calories             integer nullable   ← dùng cộng vào ngày (manual: ước lượng bằng MET, §4.6)
raw                  json nullable     (payload gốc provider; NULL khi manual)
created_at / updated_at
unique(provider, external_id)            (chỉ áp cho row có external_id; manual không vướng vì external_id NULL)
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
- `SyncActivityJob(connection, externalId)` — fetch chi tiết 1 activity → upsert `health_activities` → gọi `HealthStreakService`/cộng calo. Idempotent qua `unique(provider, external_id)`. **Khi tạo MỚI activity (calo > 0)**: gửi push + `NotificationLog` (type `activity_synced`, url `/history`) chúc mừng kèm số calo đốt được. Guard `wasRecentlyCreated` → không gửi trùng lúc re-sync/backfill/retry.
- `BackfillActivitiesJob(connection)` — chạy sau khi kết nối lần đầu, kéo N activity gần nhất.
- `RefreshExpiringTokensJob` — cron, refresh token sắp hết hạn.
- (cron) `PollActivitiesCommand` — fallback quét provider mỗi X giờ phòng webhook miss.

### 4.5 Tích hợp nghiệp vụ
- **Calo đốt (ĐÃ CHỐT — trừ vào goal)**: dùng mô hình **Net calories**.
  - Công thức ring: `net = tổng calo nạp − tổng calo đốt`, so với `calorie_goal`. Đốt nhiều → net giảm → còn budget để nạp thêm.
  - (Tương đương về toán: `goal hiển thị = calorie_goal + calo đốt`. Chọn cách net để khớp `CalorieRing` hiện tại, chỉ đổi tử số.)
  - Endpoint `GET /food/today` (`FoodController@todayStats`) cần trả thêm `calories_burned` = **tổng `health_activities.calories` của TẤT CẢ nguồn trong ngày** (Strava + manual), để FE tính net. Không phân biệt nguồn ở tầng tính toán — đã gộp ở data model.
  - Lưu activity ở `health_activities`; **không** trộn vào `meal_logs`. Calo đốt là field tổng hợp theo ngày, không phải bữa ăn.
  - Lưu ý hiển thị: nếu `net < 0` (đốt > nạp), ring không vượt 0 — show "còn dư X kcal" thay vì âm.
- **Streak**: tổng quát hoá `StreakService` — tách `recordMealActivity()` thành `recordActivity(User, date)` để cả buổi tập lẫn bữa ăn cùng đẩy chuỗi (1 ngày có hoạt động bất kỳ = giữ streak). Tránh double-count trong cùng ngày (đã có guard `last_activity_date === today`).
- **Feed**: `GET /integrations/activities` trả danh sách cho trang mới (gồm cả buổi tập manual lẫn provider).

### 4.6 Log buổi tập thủ công (fallback cho user không có Strava) — đường chính cho số đông
Không tạo bảng riêng: ghi thẳng vào `health_activities` với `provider = 'manual'`, `source = 'manual'`, `external_id = NULL`, `health_connection_id = NULL`. Nhờ đó net-calories (§4.5), streak và feed **không phải sửa logic**, chỉ thêm nguồn.

**Ước lượng calo bằng MET:** `calories ≈ MET × cân_nặng_kg × (duration_seconds / 3600)`.
- Lấy cân nặng mới nhất từ profile của user (đã có trong hệ thống BMR/TDEE).
- Bảng MET theo `type` (giá trị tham khảo, để trong config `config/health.php` cho dễ chỉnh): đi bộ ~3.5, chạy ~9.8, đạp xe ~7.5, bơi ~8.0, gym/tạ ~5.0, yoga ~3.0…
- Nếu user tự nhập calo → tôn trọng giá trị nhập, không ghi đè bằng MET.

**API:**
| Method | Route | Mô tả |
|---|---|---|
| `storeManual` | `POST /integrations/activities/manual` | Tạo buổi tập tay: `{ type, started_at, duration_seconds, distance_meters?, calories? }`. Thiếu `calories` → tính bằng MET. Trả activity vừa tạo + đẩy streak. |
| `destroyManual` | `DELETE /integrations/activities/{id}` | Xoá buổi tập tay (chỉ cho row `source = manual` thuộc user; **không** cho xoá row provider để tránh lệch với Strava). |

> Buổi tập manual cũng gọi `recordActivity(User, date)` để giữ streak, giống bữa ăn và buổi tập Strava.

---

## 5. Frontend (Vue 3 PWA)

### 5.1 Composable
`resources/js/composables/useHealthIntegration.ts`
- `connections` (state), `connect(provider)`, `disconnect(provider)`, `fetchActivities()`.
- `connect()` mở authorize URL (redirect cả tab, giống flow Google/Facebook hiện tại trong `useAuth`).

### 5.2 UI
- **Settings/Profile**: card "Kết nối ứng dụng sức khoẻ" → nút *Kết nối Strava* / trạng thái *Đã kết nối · Ngắt kết nối*.
- **Trang Activity feed** (mới, vd `pages/Activities.vue`): list buổi tập (icon theo type, quãng đường, thời lượng, calo; badge nguồn Strava/manual). Thêm vào `BottomNav` hoặc trong History.
- **Nút "+ Thêm buổi tập" (manual)**: form chọn `type` + thời lượng (+ tuỳ chọn quãng đường / tự nhập calo) → `POST /integrations/activities/manual`. Đây là lối vào chính cho user không có Strava; hiện ngay cả khi chưa kết nối provider nào.
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

> **Tiến độ thực tế (re-sequence vì "API Strava để sau cùng — chưa có kinh phí"):**
> - ✅ **Phase A — Nền tảng + Log thủ công (KHÔNG cần credential):** 2 migration (`health_connections`, `health_activities`), models, `config/health.php` (MET), tổng quát hoá `StreakService::recordActivity()`, `IntegrationController` (index/activities/storeManual/destroyManual), net-calories trong `todayStats`, routes `/integrations/*`. FE: `types/health.ts`, `useHealthIntegration`, CalorieRing net-calories, trang `/activities` (feed + form thêm buổi tập). **Type-check pass.** ✅ Đã `migrate` qua Docker (container PHP 8.4.22) — smoke test MET + model OK. *(PHP host là 8.2/8.3 nên dev/chạy artisan phải qua container: `docker compose exec backend php artisan ...`.)*
> - ✅ **Phase B — Strava provider (CODE XONG, chưa test live vì chưa có key):** abstraction `app/Services/Health/` (`TokenSet`, `HealthProvider`, `StravaProvider`, `HealthProviderFactory`, `HealthActivityWriter`); `IntegrationController` connect/callback/disconnect (state mã hoá `Crypt`, available_providers); `IntegrationWebhookController` verify+receive (public); jobs `SyncActivityJob`/`BackfillActivitiesJob`/`RefreshExpiringTokensJob` (queue `database`); scheduler refresh hourly; routes connect/callback/webhook. FE: `connect/disconnect/isConnected/isAvailable`, card Strava động, `/integrations/callback`. **route:list + php -l + type-check pass; factory/provider/writer/jobs resolve OK qua Docker.** Migrate đã chạy (PHP 8.4.22 trong container).
> - ⬜ **Phase 0 (CUỐI CÙNG — khi có kinh phí):** đăng ký Strava app tại https://www.strava.com/settings/api, điền `STRAVA_*` trong `.env`, set webhook URL public (ngrok khi dev), `queue:work` + `schedule:work` (đã có sẵn service `queue`/`scheduler` trong docker-compose). Sau đó test live: kết nối → backfill → tạo activity trên Strava → webhook → xuất hiện trong feed + cộng calo.

### Phase 0 — Chuẩn bị (½ ngày)
- [ ] **Subscribe Strava** (API gated sau paywall — bắt buộc trước khi tạo app).
- [ ] Đăng ký Strava API app, lấy client id/secret, set Authorization Callback Domain.
- [x] Thêm config + env keys (+ `.env.example`). *(code đã có; chỉ cần điền giá trị thật vào `.env`)*

### Phase 1 — Connect flow (1–2 ngày)
- [x] Migration `health_connections`.
- [x] `HealthProvider` interface + `StravaProvider` (authorizeUrl, exchangeCode, refresh).
- [x] `IntegrationController@connect/@callback/@index/@disconnect` + routes.
- [x] FE: card kết nối (trong trang `/activities`) + composable `connect/disconnect`.
- [ ] **Mốc kiểm thử LIVE**: kết nối được, thấy "Đã kết nối", token lưu mã hoá. *(chờ Phase 0 có key)*

### Phase 2 — Đồng bộ activity (2–3 ngày)
- [x] Migration `health_activities`.
- [x] Webhook controller (verify + receive) + route public.
- [x] `SyncActivityJob` + `BackfillActivitiesJob` (queue).
- [x] `RefreshExpiringTokensJob` + đăng ký vào scheduler (`routes/console.php`).
- [ ] **Mốc kiểm thử LIVE**: tạo activity trên Strava → tự xuất hiện trong DB. *(chờ Phase 0 có key)*

### Phase 3 — Nghiệp vụ + UI (2–3 ngày)
- [x] Cộng calo đốt (Net calories, tổng mọi nguồn) + cập nhật CalorieRing.
- [x] Tổng quát hoá `StreakService` để buổi tập tính streak (`recordActivity()`, `recordMealActivity()` giữ làm alias).
- [x] Trang feed activity + endpoint `GET /integrations/activities`.
- [x] **Log thủ công (§4.6)**: cột `source`/nullable trên `health_activities`, MET config, `POST/DELETE /integrations/activities/manual`, form FE "+ Thêm buổi tập". → phủ user không có Strava.
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
6. **Fallback cho user không có Strava** → **log buổi tập thủ công** ghi chung vào `health_activities` (`provider='manual'`), calo ước lượng bằng MET (§4.6). Đây là đường chính cho số đông; Strava chỉ là tăng cường cho nhóm có thiết bị. Không tạo bảng `activity_logs` riêng — thay thế luôn Phase 3 còn treo của `spec-meal-plan.md`.

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
