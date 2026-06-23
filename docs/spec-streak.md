# Spec: Daily Streak — Kéo chuỗi hàng ngày

> **App:** CaloEye — Laravel 11 (API) + Vue 3 / Inertia (Frontend)  
> **Cập nhật lần cuối:** 2026-06-23  
> **Trạng thái tổng:** 🔴 Chưa implement

---

## Mục lục
1. [Tổng quan & triết lý thiết kế](#1-tổng-quan--triết-lý-thiết-kế)
2. [Cơ chế Streak](#2-cơ-chế-streak)
3. [Cơ chế Freeze Token](#3-cơ-chế-freeze-token)
4. [Milestone Badges](#4-milestone-badges)
5. [DB Schema](#5-db-schema)
6. [Backend — StreakService](#6-backend--streakservice)
7. [API Contract](#7-api-contract)
8. [Notifications tích hợp](#8-notifications-tích-hợp)
9. [Frontend — Composable `useStreak`](#9-frontend--composable-usestreak)
10. [UI Components](#10-ui-components)
11. [Tích hợp vào màn hình hiện có](#11-tích-hợp-vào-màn-hình-hiện-có)
12. [Edge cases & rules](#12-edge-cases--rules)
13. [Danh sách file cần tạo / sửa](#13-danh-sách-file-cần-tạo--sửa)
14. [Checklist tổng](#14-checklist-tổng)

---

## 1. Tổng quan & triết lý thiết kế

### Tại sao chọn Streak thay vì điểm danh tích điểm?

| | Điểm danh (Shopee) | Streak (Duolingo) |
|--|--|--|
| Trigger mở app | Click nút nhận thưởng | Log bữa ăn thật |
| Gắn với core loop? | ❌ Tách rời | ✅ Là core loop |
| Tâm lý học | Reward | **Loss aversion** (mạnh hơn) |
| Cần "kinh tế điểm"? | ✅ Phức tạp | ❌ Không cần |
| Phù hợp app PT? | ❌ | ✅ |

**Quyết định:** Streak dựa trên hành vi thật (log bữa ăn), không phải click vào nút. User không mở app để "nhận thưởng" — họ mở app vì **không muốn mất chuỗi đang có**.

### Luồng tổng quan

```
User log bữa ăn
       │
       ▼
StreakService::recordActivity()
       │
       ├─ streak++ nếu hợp lệ
       ├─ cập nhật best_streak
       ├─ tặng freeze token nếu đạt mốc 7 ngày
       └─ kiểm tra milestone → push notification nếu milestone mới
```

---

## 2. Cơ chế Streak

### 2.1 Điều kiện tính 1 ngày hợp lệ

> User phải **log ít nhất 1 bữa ăn** trong ngày (theo timezone của user).  
> Chỉ mở app hoặc xem Home **không được tính**.

### 2.2 Logic cập nhật streak (gọi khi log bữa ăn)

```
today = ngày hiện tại (theo timezone user)
last  = streak.last_activity_date

if last == today:
    // Đã tính hôm nay rồi, không đổi
    return

if last == today - 1 day:
    // Ngày liên tiếp bình thường
    current_streak++

elif last == today - 2 days AND freeze_last_used_date == today - 1 day:
    // Hôm qua dùng freeze token để bảo vệ → vẫn tính liên tiếp
    current_streak++

else:
    // Bị đứt chuỗi (hoặc lần đầu log)
    current_streak = 1

last_activity_date = today
best_streak        = max(best_streak, current_streak)

award_token_if_eligible()   // tặng freeze token tại mốc 7, 14, 21...
check_new_milestone()       // gửi push nếu vừa qua mốc mới
```

### 2.3 Streak display states

| Trạng thái | Điều kiện | Hiển thị |
|--|--|--|
| **Active** | Đã log hôm nay | 🔥 {n} — màu cam |
| **At risk** | Chưa log hôm nay, streak > 0, sau 18:00 | 🔥 {n} — màu vàng, pulse animation |
| **Broken** | Không log qua ngày, streak reset | 💔 0 — xám |
| **Zero** | Chưa bao giờ log | — (ẩn badge) |

---

## 3. Cơ chế Freeze Token

### 3.1 Khái niệm

Freeze token = "vé cứu chuỗi" — dùng để bảo vệ streak khi lỡ không log **đúng 1 ngày**.

Lấy cảm hứng từ Duolingo Streak Shield nhưng **manual** (user chủ động dùng, không auto-dùng).

### 3.2 Earn token

| Mốc | Token nhận |
|--|--|
| Đạt streak 7 ngày | +1 |
| Đạt streak 14 ngày | +1 |
| Đạt streak 21 ngày, 28 ngày, ... (mỗi 7 ngày tiếp theo) | +1 |
| Tối đa lưu trữ | **3 tokens** |

### 3.3 Sử dụng freeze

**Điều kiện có thể dùng:**
- `freeze_tokens > 0`
- `current_streak > 0` (có chuỗi để bảo vệ)
- `last_activity_date == today - 2 days` (lỡ **đúng** hôm qua)
- `freeze_last_used_date != today - 1 day` (chưa dùng cho hôm qua)

**Khi dùng:**
```
freeze_last_used_date = today - 1 day   // đánh dấu đã bảo vệ hôm qua
freeze_tokens--
```

**Sau đó khi log bữa ăn hôm nay:**
- Logic ở mục 2.2 nhận thấy `freeze_last_used_date == today - 1` → streak vẫn tiếp tục

### 3.4 Giới hạn

- Chỉ bảo vệ được **1 ngày liên tiếp** — không thể dùng 2 token để bỏ qua 2 ngày
- Không thể dùng trước (chỉ dùng **sau** khi đã bỏ lỡ 1 ngày)
- Mỗi ngày bị bỏ chỉ dùng được 1 token (không dùng trùng)

---

## 4. Milestone Badges

### 4.1 Danh sách mốc

| Ngày | Tên badge | Emoji |
|--|--|--|
| 3 | Khởi đầu tốt | 🌱 |
| 7 | 1 tuần liên tiếp | 🔥 |
| 14 | 2 tuần mạnh mẽ | ⚡ |
| 30 | 1 tháng kiên trì | 💪 |
| 60 | Siêu kiên nhẫn | 🏆 |
| 100 | Huyền thoại | 👑 |

### 4.2 Khi đạt milestone

1. Lưu vào bảng `streak_milestones`
2. Gửi push notification ngay lập tức (xem mục 8)
3. Frontend hiển thị `MilestoneToast` overlay với animation confetti

---

## 5. DB Schema

### 5.1 Bảng `user_streaks`

```sql
CREATE TABLE user_streaks (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id               BIGINT UNSIGNED NOT NULL UNIQUE,
    current_streak        INT UNSIGNED    NOT NULL DEFAULT 0,
    best_streak           INT UNSIGNED    NOT NULL DEFAULT 0,
    last_activity_date    DATE            NULL,         -- ngày cuối cùng được tính
    freeze_tokens         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    freeze_last_used_date DATE            NULL,         -- ngày được bảo vệ bởi freeze
    created_at            TIMESTAMP       NULL,
    updated_at            TIMESTAMP       NULL,

    CONSTRAINT fk_streak_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### 5.2 Bảng `streak_milestones`

```sql
CREATE TABLE streak_milestones (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      BIGINT UNSIGNED NOT NULL,
    days         SMALLINT UNSIGNED NOT NULL,  -- 3, 7, 14, 30, 60, 100
    achieved_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    push_sent_at TIMESTAMP       NULL,

    UNIQUE KEY uq_user_milestone (user_id, days),
    CONSTRAINT fk_milestone_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

---

## 6. Backend — StreakService

**File:** `app/Services/StreakService.php`

```php
class StreakService
{
    // Gọi sau khi user tạo meal log thành công
    public function recordActivity(User $user): void
    {
        $streak  = $user->streak()->firstOrCreate(['user_id' => $user->id]);
        $today   = now()->setTimezone($user->timezone ?? 'UTC')->toDateString();
        $changed = false;

        if ($streak->last_activity_date === $today) {
            return; // đã tính hôm nay
        }

        $yesterday   = now()->subDay()->toDateString();
        $twoDaysAgo  = now()->subDays(2)->toDateString();
        $freezeUsed  = $streak->freeze_last_used_date;

        if ($streak->last_activity_date === $yesterday) {
            $streak->current_streak++;
        } elseif ($streak->last_activity_date === $twoDaysAgo && $freezeUsed === $yesterday) {
            $streak->current_streak++;    // freeze đã bảo vệ hôm qua
        } else {
            $streak->current_streak = 1; // reset hoặc bắt đầu mới
        }

        $streak->last_activity_date = $today;
        $streak->best_streak        = max($streak->best_streak, $streak->current_streak);

        $this->awardFreezeToken($streak);

        $streak->save();

        $this->checkAndRecordMilestone($user, $streak);
    }

    // Gọi khi user bấm "Dùng freeze token"
    public function useFreeze(User $user): void
    {
        $streak    = $user->streak;
        $yesterday = now()->subDay()->toDateString();
        $twoDaysAgo = now()->subDays(2)->toDateString();

        throw_if($streak->freeze_tokens <= 0,            \Exception::class, 'Không còn freeze token');
        throw_if($streak->current_streak <= 0,           \Exception::class, 'Không có chuỗi để bảo vệ');
        throw_if($streak->last_activity_date !== $twoDaysAgo, \Exception::class, 'Chỉ dùng được khi bỏ lỡ đúng 1 ngày');
        throw_if($streak->freeze_last_used_date === $yesterday, \Exception::class, 'Đã dùng freeze cho ngày này');

        $streak->freeze_tokens--;
        $streak->freeze_last_used_date = $yesterday;
        $streak->save();
    }

    private function awardFreezeToken(UserStreak $streak): void
    {
        // Tặng 1 token tại mỗi mốc 7 ngày (7, 14, 21, ...)
        if ($streak->current_streak > 0
            && $streak->current_streak % 7 === 0
            && $streak->freeze_tokens < 3
        ) {
            $streak->freeze_tokens++;
        }
    }

    private function checkAndRecordMilestone(User $user, UserStreak $streak): void
    {
        $milestones = [3, 7, 14, 30, 60, 100];

        foreach ($milestones as $days) {
            if ($streak->current_streak >= $days) {
                $existing = StreakMilestone::where('user_id', $user->id)
                                          ->where('days', $days)
                                          ->first();
                if (!$existing) {
                    $milestone = StreakMilestone::create([
                        'user_id'     => $user->id,
                        'days'        => $days,
                        'achieved_at' => now(),
                    ]);
                    $this->sendMilestonePush($user, $milestone);
                }
            }
        }
    }

    private function sendMilestonePush(User $user, StreakMilestone $milestone): void
    {
        $labels = [
            3   => ['🌱 Khởi đầu tốt!',       "Bạn đã log {$milestone->days} ngày liên tiếp. Tiếp tục nhé!"],
            7   => ['🔥 1 tuần liên tiếp!',     "Chuỗi 7 ngày — xuất sắc! Bạn vừa nhận được 1 Freeze Token."],
            14  => ['⚡ 2 tuần mạnh mẽ!',       "Chuỗi 14 ngày — bạn đang xây dựng thói quen thật sự!"],
            30  => ['💪 1 tháng kiên trì!',      "30 ngày liên tiếp! Đây là thành tích đáng tự hào."],
            60  => ['🏆 Siêu kiên nhẫn!',        "60 ngày! Sức khoẻ của bạn đang thay đổi từng ngày."],
            100 => ['👑 Huyền thoại CaloEye!',   "100 ngày liên tiếp. Bạn là tấm gương cho cộng đồng!"],
        ];

        [$title, $body] = $labels[$milestone->days] ?? ["🎉 Cột mốc {$milestone->days} ngày!", "Tuyệt vời!"];

        // Gửi push qua FcmService (đã có từ spec-notifications)
        app(FcmService::class)->sendToUser($user, $title, $body, [
            'type'      => 'milestone',
            'days'      => $milestone->days,
            'deep_link' => '/home',
        ]);

        $milestone->update(['push_sent_at' => now()]);
    }
}
```

---

## 7. API Contract

### 7.1 GET `/streak`
Lấy toàn bộ thông tin streak của user hiện tại.

```json
// Response 200
{
    "current_streak":       15,
    "best_streak":          23,
    "last_activity_date":   "2026-06-22",
    "freeze_tokens":        2,
    "freeze_last_used_date": null,
    "can_use_freeze":       false,
    "is_logged_today":      true,
    "streak_at_risk":       false,
    "achieved_milestones":  [3, 7, 14],
    "next_milestone":       30
}
```

**Logic computed fields:**
- `can_use_freeze`: `freeze_tokens > 0 AND current_streak > 0 AND last_activity_date == today-2 AND freeze_last_used_date != today-1`
- `is_logged_today`: `last_activity_date == today`
- `streak_at_risk`: `current_streak > 0 AND NOT is_logged_today`
- `next_milestone`: milestone nhỏ nhất trong [3,7,14,30,60,100] mà `current_streak < milestone`

### 7.2 POST `/streak/freeze`
Dùng 1 freeze token để bảo vệ chuỗi.

```json
// Request — no body

// Response 200
{
    "message":              "Đã dùng Freeze Token để bảo vệ chuỗi!",
    "freeze_tokens":        1,
    "freeze_last_used_date": "2026-06-22"
}

// Response 422
{ "message": "Không đủ điều kiện dùng freeze token", "reason": "Chỉ dùng được khi bỏ lỡ đúng 1 ngày" }
```

### 7.3 Integration: `POST /meal-logs` (sửa endpoint hiện có)
Sau khi tạo meal log thành công, backend tự động gọi `StreakService::recordActivity()`.  
Response **thêm** field `streak`:

```json
// Response 201 (thêm field streak)
{
    "id": 123,
    "...": "...",
    "streak": {
        "current_streak":  16,
        "is_new_day":      true,      // true nếu vừa tăng streak
        "new_milestone":   null       // số ngày nếu vừa đạt mốc mới, null nếu không
    }
}
```

Field `streak` cho phép frontend hiện `MilestoneToast` ngay sau khi log mà không cần gọi thêm API.

---

## 8. Notifications tích hợp

Tích hợp vào hệ thống scheduler đã có (`app/Console/Commands/Notifications/`).

### 8.1 Streak At-Risk Reminder (21:00)

**Command:** `notify:streak-risk`  
**Schedule:** Mỗi phút (lọc theo timezone user), target 21:00

**Điều kiện gửi:**
- `current_streak > 0`
- `is_logged_today == false`
- Chưa gửi reminder này hôm nay (track qua `notification_logs`)

```
Tiêu đề: "🔥 Chuỗi {n} ngày sắp bị gián đoạn!"
Nội dung: "Bạn chưa log bữa ăn nào hôm nay. Còn vài tiếng để giữ chuỗi nhé!"
Deep link: /home
```

### 8.2 Freeze Token Reminder (09:00 hôm sau)

**Command:** `notify:streak-freeze-remind`  
**Schedule:** 09:00 mỗi ngày (UTC), chạy 1 lần

**Điều kiện gửi:**
- `current_streak > 0`
- `last_activity_date == today - 2` (bỏ lỡ hôm qua)
- `freeze_tokens > 0`
- `freeze_last_used_date != yesterday`

```
Tiêu đề: "💔 Ôi không! Chuỗi {n} ngày bị gián đoạn"
Nội dung: "Bạn có {k} Freeze Token. Dùng ngay để cứu chuỗi!"
Deep link: /home  (mở StreakModal)
```

### 8.3 Milestone Push

Gửi ngay khi đạt mốc (trong `StreakService::sendMilestonePush`), không cần cron.  
Xem nội dung ở mục 6.

### 8.4 Bảng `notification_logs` (đã có)

Thêm 2 `notification_type` mới:
- `streak_risk`
- `streak_freeze_remind`

Đảm bảo không gửi duplicate trong cùng 1 ngày.

---

## 9. Frontend — Composable `useStreak`

**File:** `resources/js/composables/useStreak.ts`

```typescript
interface StreakData {
    current_streak:        number
    best_streak:           number
    last_activity_date:    string | null
    freeze_tokens:         number
    freeze_last_used_date: string | null
    can_use_freeze:        boolean
    is_logged_today:       boolean
    streak_at_risk:        boolean
    achieved_milestones:   number[]
    next_milestone:        number | null
}

interface MealLogStreakResult {
    current_streak: number
    is_new_day:     boolean
    new_milestone:  number | null
}

export function useStreak() {
    const streak   = ref<StreakData | null>(null)
    const loading  = ref(false)
    const newMilestone = ref<number | null>(null)  // trigger MilestoneToast

    // Lấy streak từ server
    async function fetchStreak(): Promise<void>

    // Dùng freeze token
    async function useFreeze(): Promise<void>

    // Gọi sau khi log bữa ăn (nhận data từ response meal-log)
    function onMealLogged(result: MealLogStreakResult): void {
        if (!streak.value || !result.is_new_day) return
        streak.value.current_streak = result.current_streak
        streak.value.is_logged_today = true
        streak.value.streak_at_risk  = false
        if (result.new_milestone) {
            newMilestone.value = result.new_milestone
        }
    }

    // Computed
    const streakLabel = computed(() => {
        if (!streak.value || streak.value.current_streak === 0) return null
        return `🔥 ${streak.value.current_streak}`
    })

    const milestoneData = computed(() => {
        // trả về { days, name, emoji } cho milestone hiện tại
        const milestones = [
            { days: 3,   name: 'Khởi đầu tốt',    emoji: '🌱' },
            { days: 7,   name: '1 tuần liên tiếp', emoji: '🔥' },
            { days: 14,  name: '2 tuần mạnh mẽ',   emoji: '⚡' },
            { days: 30,  name: '1 tháng kiên trì',  emoji: '💪' },
            { days: 60,  name: 'Siêu kiên nhẫn',    emoji: '🏆' },
            { days: 100, name: 'Huyền thoại',        emoji: '👑' },
        ]
        return milestones.find(m => m.days === newMilestone.value) ?? null
    })

    return {
        streak, loading, newMilestone, streakLabel, milestoneData,
        fetchStreak, useFreeze, onMealLogged,
    }
}
```

---

## 10. UI Components

### 10.1 `StreakBadge.vue`

**File:** `resources/js/components/streak/StreakBadge.vue`  
**Dùng ở:** Home header, Profile page

```
┌────────┐
│ 🔥 15  │  ← tap → mở StreakModal
└────────┘

States:
- Active  (streak > 0, logged today):      🔥 {n}  — cam #FF6B35
- At risk (streak > 0, not logged today):  🔥 {n}  — vàng + pulse animation
- Zero    (current_streak == 0):           ẩn badge (không hiển thị)
```

### 10.2 `StreakModal.vue`

**File:** `resources/js/components/streak/StreakModal.vue`  
Bottom sheet iOS-style, mở khi tap `StreakBadge`.

```
┌─────────────────────────────────────┐
│         ⎯  (drag handle)           │
│                                     │
│         🔥  15 ngày                 │  ← current_streak, lớn, cam
│    Chuỗi tốt nhất: 23 ngày         │
│                                     │
│  ─────── TIẾN ĐỘ ─────────────     │
│  ●──────────────────○  14/30 ngày  │  ← progress bar đến next_milestone
│  Còn 15 ngày nữa để đạt 💪 30      │
│                                     │
│  ─────── FREEZE TOKENS ────────    │
│  ❄️ ❄️ ○   (2/3 tokens)            │
│                                     │
│  [Dùng Freeze Token]                │  ← hiện nếu can_use_freeze == true
│  (Bảo vệ chuỗi khi bỏ lỡ 1 ngày)  │
│                                     │
│  ─────── HUY HIỆU ĐẠT ĐƯỢC ──────  │
│  🌱 3   🔥 7   ⚡ 14               │  ← achieved_milestones
│  💪 30  🏆 60  👑 100  (xám, chưa) │
└─────────────────────────────────────┘
```

### 10.3 `MilestoneToast.vue`

**File:** `resources/js/components/streak/MilestoneToast.vue`  
Overlay toàn màn hình, tự dismiss sau 4 giây.

```
┌─────────────────────────────────────┐
│                                     │
│   ✨ confetti animation ✨           │
│                                     │
│           🔥                        │  ← emoji to lớn (80px)
│                                     │
│     1 tuần liên tiếp!               │  ← tên milestone
│   Bạn đã log 7 ngày liên tiếp!     │
│                                     │
│     + 1 Freeze Token nhận được ❄️  │  ← chỉ hiện ở mốc 7, 14, 21...
│                                     │
│         [Tuyệt vời! →]             │  ← tap để dismiss
└─────────────────────────────────────┘
```

**Trigger:** `newMilestone.value !== null` trong `useStreak()`.  
Sau khi dismiss: `newMilestone.value = null`.

### 10.4 `StreakRiskBanner.vue`

**File:** `resources/js/components/streak/StreakRiskBanner.vue`  
Banner nhỏ trên Home, hiện sau 18:00 nếu chưa log hôm nay.

```
┌─────────────────────────────────────────┐
│ ⚠️  Chưa log hôm nay! Chuỗi 🔥15 ngày  │
│ đang bị đe dọa.           [Log ngay →] │
└─────────────────────────────────────────┘
```

**Điều kiện hiện:**
- `streak.streak_at_risk == true`
- Giờ hiện tại ≥ 18:00 (tính client-side)
- User chưa dismiss banner này (lưu trạng thái dismiss bằng `sessionStorage`)

---

## 11. Tích hợp vào màn hình hiện có

### 11.1 `Home.vue`

Thêm vào header (cạnh notification bell):
```vue
<StreakBadge @click="streakModalOpen = true" />
<StreakModal v-model:open="streakModalOpen" />
<MilestoneToast v-if="newMilestone" @close="newMilestone = null" />
<StreakRiskBanner v-if="showRiskBanner" />
```

Gọi `fetchStreak()` trong `onMounted` (song song với `fetchTodayStats`).

### 11.2 `useMealLog.ts` (sửa composable hiện có)

Sau khi `createMealLog` thành công, kiểm tra `response.streak` và gọi `onMealLogged()`:

```typescript
const { onMealLogged } = useStreak()

async function createMealLog(payload) {
    const res = await apiFetch('/meal-logs', { method: 'POST', body: payload })
    // ...existing logic...
    if (res.streak) {
        onMealLogged(res.streak)
    }
}
```

### 11.3 `Profile` page

Thêm row "Streak & Kỷ lục" với `StreakBadge` và best streak:

```
┌────────────────────────────────────┐
│ 🔥 Streak hiện tại    15 ngày   > │  ← mở StreakModal
│ 🏅 Kỷ lục cao nhất   23 ngày     │
└────────────────────────────────────┘
```

---

## 12. Edge cases & rules

| Tình huống | Xử lý |
|--|--|
| User đổi timezone | Streak tính theo timezone **tại thời điểm log** — không recompute lại lịch sử |
| Log nhiều bữa trong 1 ngày | Chỉ tính 1 lần/ngày — lần đầu tiên trong ngày mới update streak |
| Xóa bữa ăn duy nhất trong ngày | **Không giảm streak** — streak chỉ tăng, không trừ khi xóa |
| Dùng freeze lúc 23:59 | Hợp lệ — `freeze_last_used_date` là ngày hiện tại - 1, không phụ thuộc giờ |
| Internet mất khi log | Streak update phía backend khi request đến — nếu offline, streak update khi reconnect |
| App guest (chưa đăng nhập) | Không có streak — ẩn toàn bộ streak UI |
| Freeze tokens > 3 (bug) | `awardFreezeToken` có guard `freeze_tokens < 3` — không bao giờ vượt quá 3 |
| Milestone đã đạt trong lần trước (best_streak cao hơn) | `UNIQUE KEY (user_id, days)` đảm bảo không duplicate — milestone chỉ ghi nhận 1 lần |

---

## 13. Danh sách file cần tạo / sửa

### Backend — Tạo mới

| File | Mô tả |
|--|--|
| `app/Models/UserStreak.php` | Model cho bảng `user_streaks` |
| `app/Models/StreakMilestone.php` | Model cho bảng `streak_milestones` |
| `app/Services/StreakService.php` | Core logic: recordActivity, useFreeze, milestone |
| `app/Http/Controllers/Api/V1/StreakController.php` | GET /streak, POST /streak/freeze |
| `app/Console/Commands/Notifications/SendStreakRiskReminders.php` | Command `notify:streak-risk` |
| `app/Console/Commands/Notifications/SendFreezeSuggestions.php` | Command `notify:streak-freeze-remind` |
| `database/migrations/xxxx_create_user_streaks_table.php` | Migration user_streaks |
| `database/migrations/xxxx_create_streak_milestones_table.php` | Migration streak_milestones |

### Backend — Sửa

| File | Việc cần làm |
|--|--|
| `app/Http/Controllers/Api/V1/FoodController.php` | Sau khi tạo meal log → gọi `StreakService::recordActivity()`, thêm `streak` vào response |
| `app/Models/User.php` | Thêm relation `hasOne(UserStreak::class)` |
| `routes/api_v1.php` | Thêm routes `/streak` và `/streak/freeze` |
| `routes/console.php` | Đăng ký `notify:streak-risk` (mỗi phút) và `notify:streak-freeze-remind` (09:00 daily) |

### Frontend — Tạo mới

| File | Mô tả |
|--|--|
| `resources/js/composables/useStreak.ts` | State + methods |
| `resources/js/components/streak/StreakBadge.vue` | Badge 🔥 {n} trên header |
| `resources/js/components/streak/StreakModal.vue` | Bottom sheet chi tiết |
| `resources/js/components/streak/MilestoneToast.vue` | Overlay ăn mừng milestone |
| `resources/js/components/streak/StreakRiskBanner.vue` | Banner cảnh báo 18:00 |

### Frontend — Sửa

| File | Việc cần làm |
|--|--|
| `resources/js/pages/Home.vue` | Import StreakBadge, StreakModal, MilestoneToast, StreakRiskBanner; gọi fetchStreak() |
| `resources/js/composables/useMealLog.ts` | Sau createMealLog → gọi `onMealLogged(res.streak)` |
| `resources/js/pages/Profile.vue` | Thêm streak row (current + best) |

---

## 14. Checklist tổng

### Phase 1 — DB & Core Service

- [ ] Migration `user_streaks`
- [ ] Migration `streak_milestones`
- [ ] Model `UserStreak` (fillable, casts, relation → user)
- [ ] Model `StreakMilestone` (fillable, casts)
- [ ] Relation `User::streak()` → `hasOne(UserStreak::class)`
- [ ] `StreakService::recordActivity()` — logic đếm streak
- [ ] `StreakService::useFreeze()` — validation + update
- [ ] `StreakService::awardFreezeToken()` — tặng token tại mốc 7 ngày
- [ ] `StreakService::checkAndRecordMilestone()` — lưu milestone + push

### Phase 2 — API

- [ ] `StreakController::show()` — `GET /streak` (computed fields: can_use_freeze, is_logged_today, streak_at_risk, next_milestone)
- [ ] `StreakController::useFreeze()` — `POST /streak/freeze`
- [ ] Thêm routes vào `routes/api_v1.php`
- [ ] Sửa `FoodController` (hoặc meal log endpoint): gọi `recordActivity()` sau khi tạo thành công, thêm `streak` vào response

### Phase 3 — Frontend Composable & Components

- [ ] `useStreak.ts`: fetchStreak, useFreeze, onMealLogged, streakLabel, milestoneData
- [ ] `StreakBadge.vue`: hiển thị 🔥{n}, 3 states (active / at-risk pulse / ẩn), emit click
- [ ] `StreakModal.vue`: current streak, best, progress bar, freeze tokens UI, button dùng freeze, milestone badges grid
- [ ] `MilestoneToast.vue`: overlay animation, emoji lớn, tên milestone, badge freeze token nếu earned, auto-dismiss 4s
- [ ] `StreakRiskBanner.vue`: hiện sau 18:00 nếu `streak_at_risk`, sessionStorage dismiss, deep link /home

### Phase 4 — Tích hợp màn hình

- [ ] `Home.vue`: thêm `StreakBadge` vào header, mount `StreakModal` + `MilestoneToast` + `StreakRiskBanner`, gọi `fetchStreak()` trong `onMounted`
- [ ] `useMealLog.ts`: sau log thành công → `onMealLogged(res.streak)`
- [ ] `Profile.vue`: thêm streak card (current + best)

### Phase 5 — Notifications

- [ ] Command `notify:streak-risk` — filter user chưa log hôm nay, streak > 0, gửi 21:00
- [ ] Command `notify:streak-freeze-remind` — filter user bỏ hôm qua + có token, gửi 09:00
- [ ] Đăng ký 2 commands trong `routes/console.php`
- [ ] `notification_logs` track `streak_risk` và `streak_freeze_remind` để không spam

### Phase 6 — Polish & Edge cases

- [ ] Test: log lần 2 trong ngày → streak không tăng thêm
- [ ] Test: xóa bữa ăn duy nhất trong ngày → streak không giảm
- [ ] Test: freeze token không vượt quá 3
- [ ] Test: milestone không ghi nhận 2 lần (UNIQUE KEY)
- [ ] Test: guest user không thấy streak UI

---

*File này được cập nhật mỗi khi hoàn thành một task. Kiểm tra checklist trước khi bắt đầu implement.*
