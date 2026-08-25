<script setup lang="ts">
import { useMealLog } from '@/composables/useMealLog'
import { useAuthStore } from '@/stores/auth'
import { useNotifications } from '@/composables/useNotifications'
import { useStreak } from '@/composables/useStreak'
import { useWater } from '@/composables/useWater'
import { useWeight } from '@/composables/useWeight'
import { currentMealSlot, useQuickLog, type FrequentMealItem } from '@/composables/useQuickLog'
import { useToast } from '@/composables/useToast'
import { usePublicConfig } from '@/composables/usePublicConfig'
import { apiFetch } from '@/utils/api'
import { setAppBadge } from '@/utils/badge'
import type { MealLogEntry } from '@/types/meal'
import CaloeyeCharacter from '@/components/caloeye/Character.vue'
import HomeCalorieRing from '@/components/home/CalorieRing.vue'
import NotificationsPermissionBanner from '@/components/notifications/PermissionBanner.vue'
import NotificationPanel from '@/components/notifications/NotificationPanel.vue'
import StreakBadge from '@/components/streak/StreakBadge.vue'
import StreakModal from '@/components/streak/StreakModal.vue'
import MilestoneToast from '@/components/streak/MilestoneToast.vue'
import DailyTasksCard from '@/components/home/DailyTasksCard.vue'

const store = useAuthStore()
const { todayStats, loading, fetchTodayStats, relogMeal } = useMealLog()
const { permission } = useNotifications()
const { streak, newMilestone, milestoneInfo, showRiskBanner, earnedTokenAtMilestone,
        streakCount, fetchStreak, useFreeze } = useStreak()
const { fetchWaterToday } = useWater()
const { history: weightHistory, fetchHistory: fetchWeightHistory } = useWeight()
const { frequentItems, fetchFrequent } = useQuickLog()
const toast = useToast()

// Flag admin: tắt chat AI → ẩn nút vào chat ở Home
const { loadPublicConfig, flag } = usePublicConfig()
const chatEnabled = computed(() => flag(c => c.ai.chat_enabled))

// Reset frequent items khi đăng nhập từ guest
watch(() => store.isGuest, (isGuest) => {
  if (!isGuest && store.user) {
    frequentItems.value = []
    fetchFrequent(currentMealSlot(), 4)
  }
})

const quickAddTarget = ref<FrequentMealItem | null>(null)
const quickAddSaving = ref(false)

function openQuickAdd(item: FrequentMealItem) {
  quickAddTarget.value = item
}

async function confirmQuickAdd() {
  if (!quickAddTarget.value) return
  quickAddSaving.value = true
  const ok = await relogMeal(quickAddTarget.value.last_log_id)
  quickAddSaving.value = false
  quickAddTarget.value = null
  if (ok) {
    toast.success('Đã thêm vào hôm nay')
    fetchTodayStats()
  } else {
    toast.error('Không thể thêm món này')
  }
}

const panelOpen    = ref(false)
const streakOpen   = ref(false)
const unreadCount  = ref(0)

const WEIGHT_REMINDER_KEY = 'weight_reminder_dismissed_date'
const weightReminderDismissed = ref(localStorage.getItem(WEIGHT_REMINDER_KEY) === localDateStr())

const lastWeighDate = computed(() => {
  const entries = weightHistory.value?.entries ?? []
  return entries.length ? entries[entries.length - 1].logged_date : null
})
const daysSinceWeigh = computed(() => {
  if (!lastWeighDate.value) return Infinity
  const diffMs = Date.now() - new Date(lastWeighDate.value + 'T00:00:00').getTime()
  return Math.floor(diffMs / 86400000)
})
const showWeightReminder = computed(() => daysSinceWeigh.value >= 7 && !weightReminderDismissed.value)

function dismissWeightReminder() {
  localStorage.setItem(WEIGHT_REMINDER_KEY, localDateStr())
  weightReminderDismissed.value = true
}

const EMAIL_VERIFY_KEY = 'email_verify_reminder_dismissed_date'
const emailVerifyDismissed = ref(localStorage.getItem(EMAIL_VERIFY_KEY) === localDateStr())
const showEmailVerifyReminder = computed(() => store.user && !store.user.email_verified && !emailVerifyDismissed.value)

function dismissEmailVerifyReminder() {
  localStorage.setItem(EMAIL_VERIFY_KEY, localDateStr())
  emailVerifyDismissed.value = true
}

async function fetchUnreadCount() {
  if (!store.token) return
  try {
    const logs = await apiFetch<{ read_at: string | null }[]>('/notifications/history')
    unreadCount.value = logs.filter(l => !l.read_at).length
  } catch {}
}

function openPanel() {
  panelOpen.value = true
}

watch(panelOpen, (v) => {
  if (!v) {
    // Panel đóng → reset count vì user đã xem
    unreadCount.value = 0
  }
})

// Đồng bộ badge trên icon app với số chưa đọc (mở app, đóng panel, đọc hết)
watch(unreadCount, (n) => setAppBadge(n))

const consumed = computed(() => todayStats.value?.total_calories ?? 0)
const burned   = computed(() => todayStats.value?.calories_burned ?? 0)
const goal     = computed(() => store.user?.calorie_goal ?? 2000)

const macros = computed(() => {
  const s = todayStats.value
  const proteinGoal = Math.round(goal.value * 0.3 / 4)
  const carbGoal    = Math.round(goal.value * 0.45 / 4)
  const fatGoal     = Math.round(goal.value * 0.25 / 9)
  return [
    { label: 'Protein',   value: s?.total_protein ?? 0, max: proteinGoal, unit: 'g', color: 'var(--color-calor-green)' },
    { label: 'Carbs',     value: s?.total_carbs   ?? 0, max: carbGoal,    unit: 'g', color: '#e0a86a' },
    { label: 'Chất béo',  value: s?.total_fat     ?? 0, max: fatGoal,     unit: 'g', color: '#c98b8b' },
  ]
})

const meals = computed(() => todayStats.value?.meals ?? [])

// Gộp nhiều món chụp cùng 1 ảnh + cùng thời điểm thành 1 cụm — như màn Lịch sử.
type MealRow =
  | ({ kind: 'single' } & MealLogEntry)
  | { kind: 'group'; id: string; image_url: string | null; logged_at: string; items: MealLogEntry[]; calories: number }

const mealRows = computed<MealRow[]>(() => {
  const out: MealRow[] = []
  const map = new Map<string, Extract<MealRow, { kind: 'group' }>>()
  for (const m of meals.value) {
    if (m.image_url) {
      const key = `${m.logged_at}|${m.image_url}`
      let g = map.get(key)
      if (!g) {
        g = { kind: 'group', id: key, image_url: m.image_url ?? null, logged_at: m.logged_at, items: [], calories: 0 }
        map.set(key, g)
        out.push(g)
      }
      g.items.push(m)
      g.calories += m.calories
    } else {
      out.push({ kind: 'single', ...m })
    }
  }
  // Cụm chỉ 1 món → hạ về dòng lẻ.
  return out.map(r => (r.kind === 'group' && r.items.length === 1) ? { kind: 'single', ...r.items[0] } : r)
})

const userName = computed(() => store.user?.name?.split(' ').at(-1) ?? 'bạn')
const userInitial = computed(() => store.user?.name?.[0]?.toUpperCase() ?? '?')

onMounted(() => {
  loadPublicConfig()
  if (store.token) {
    fetchTodayStats()
    fetchUnreadCount()
    fetchStreak()
    fetchWaterToday()
    fetchWeightHistory(30)
    fetchFrequent(currentMealSlot(), 4)
  }
})
</script>

<template>
  <div class="pb-4">
    <!-- ══ Curved gradient header ══ -->
    <div class="relative overflow-hidden text-white rounded-b-[34px] px-5 pt-9 pb-[72px] bg-gradient-to-b from-[var(--color-matcha-mid)] to-[var(--color-calor-dark)] animate-fadeInUp" style="opacity:0">
      <div class="absolute -right-5 -top-3 text-[120px] leading-none opacity-[0.14] pointer-events-none select-none">🥑</div>
      <div class="relative flex items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="text-[12px] text-white/80 capitalize">{{ new Date().toLocaleDateString('vi-VN', { weekday: 'long', day: 'numeric', month: 'long' }) }}</p>
          <h1 class="font-display text-[22px] font-bold leading-tight mt-0.5 truncate">Hi {{ userName }} 👋</h1>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0">
          <!-- Streak pill -->
          <button
            class="inline-flex items-center gap-1 h-9 px-3 rounded-full bg-white/22 text-white text-[12px] font-semibold ios-press"
            :class="showRiskBanner ? 'ring-1 ring-white/60' : ''"
            @click="streakOpen = true"
          >🔥 {{ streakCount }}</button>

          <!-- Bell icon -->
          <button
            class="relative w-9 h-9 flex items-center justify-center rounded-full bg-white/22 ios-press"
            @click="openPanel"
          >
            <svg viewBox="0 0 24 24" class="w-5 h-5" :class="permission === 'denied' ? 'text-white/50' : 'text-white'" fill="currentColor">
              <path v-if="permission !== 'denied'" d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/>
              <path v-else d="M20 18.69L7.84 6.14 5.27 3.49 4 4.76l2.8 2.8v.01c-.52.99-.8 2.16-.8 3.42v5l-2 2v1h13.73l2 2L21 19.72l-1-1.03zM12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6.27V11c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68c-.15.03-.29.08-.43.12L18 10.73v5z"/>
            </svg>
            <span
              v-if="unreadCount > 0"
              class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 bg-ios-red rounded-full border border-white flex items-center justify-center"
            >
              <span class="text-[10px] font-bold text-white px-0.5 leading-none">{{ unreadCount > 9 ? '9+' : unreadCount }}</span>
            </span>
            <span
              v-else-if="permission === 'default'"
              class="absolute top-1 right-1 w-2 h-2 bg-ios-red rounded-full border border-white"
            />
          </button>
        </div>
      </div>
      <div class="relative mt-3 flex items-center gap-2.5">
        <CaloeyeCharacter
          :mood="consumed >= goal ? 'happy' : consumed >= goal * 0.8 ? 'motivate' : 'wave'"
          :size="46"
          class="flex-shrink-0 drop-shadow-sm"
        />
        <p class="text-[13px] text-white/90 leading-relaxed">
          <template v-if="consumed >= goal">Đủ chỉ tiêu hôm nay rồi, đỉnh 🎉 Vận động nhẹ cho nhẹ người nha</template>
          <template v-else>Còn <b class="font-semibold">{{ (goal - consumed).toLocaleString('vi') }} kcal</b> để đạt mục tiêu — nạp healthy nào ✨</template>
        </p>
      </div>
    </div>

    <!-- ══ Floating ring card (đè lên header) ══ -->
    <div class="relative -mt-[58px] mx-[18px] bg-white rounded-[26px] p-[18px] shadow-[0_14px_30px_rgba(60,74,52,0.12)] animate-fadeInUp delay-1" style="opacity:0">
      <HomeCalorieRing :consumed="consumed" :goal="goal" :burned="burned" horizontal />
      <div v-if="loading" class="mt-2 flex justify-center">
        <div class="w-4 h-4 rounded-full border-2 border-calor-green border-t-transparent animate-spin"/>
      </div>
    </div>

    <!-- ══ Macro tiles + scan CTA ══ -->
    <div class="px-[18px] pt-4">
      <div class="flex gap-2.5">
        <div
          v-for="m in macros" :key="m.label"
          class="flex-1 bg-white rounded-[18px] px-3 py-3 shadow-[0_6px_16px_rgba(60,74,52,0.05)]"
        >
          <p class="text-[11px] text-ios-gray">{{ m.label }}</p>
          <p class="font-display text-[17px] font-bold text-calor-deep leading-tight tabular-nums">{{ m.value }}<span class="text-[11px] text-ios-gray2 font-medium">/{{ m.max }}</span></p>
          <div class="h-[5px] rounded-full bg-[var(--color-line)] mt-1.5 overflow-hidden">
            <div class="h-full rounded-full transition-all duration-1000" :style="`width:${Math.min((m.value / m.max) * 100, 100)}%;background:${m.color}`"/>
          </div>
        </div>
      </div>

      <NuxtLink
        to="/scan"
        class="mt-3.5 bg-matcha rounded-[22px] px-[18px] py-4 flex items-center gap-3.5 text-white shadow-lg shadow-matcha/30 ios-press"
      >
        <div class="w-[46px] h-[46px] rounded-2xl bg-white/22 flex items-center justify-center text-2xl flex-shrink-0">📷</div>
        <div class="flex-1 min-w-0">
          <p class="font-display text-[15px] font-bold">Ghi bữa ăn ngay</p>
          <p class="text-[11.5px] text-white/85">Chụp ảnh · Nhập tay · Tư vấn AI</p>
        </div>
        <span class="text-xl leading-none">›</span>
      </NuxtLink>
    </div>

    <NotificationsPermissionBanner class="pt-3"/>

    <!-- Nhắc xác thực email -->
    <div
      v-if="showEmailVerifyReminder"
      class="mx-5 mb-4 bg-white rounded-[16px] px-4 py-3.5 flex items-center gap-3 shadow-sm animate-fadeInUp delay-1"
      style="opacity:0"
    >
      <div class="w-9 h-9 rounded-full bg-ios-orange/10 flex items-center justify-center text-lg flex-shrink-0">✉️</div>
      <NuxtLink to="/profile/verify-email" class="flex-1 min-w-0">
        <p class="text-[13px] font-medium text-black leading-snug">Xác thực email để bảo vệ tài khoản của bạn</p>
      </NuxtLink>
      <button class="ios-press p-1 text-ios-gray3 flex-shrink-0" @click="dismissEmailVerifyReminder">
        <svg viewBox="0 0 24 24" class="w-4 h-4" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
      </button>
    </div>

    <!-- Nhắc cập nhật cân nặng (≥7 ngày chưa ghi) -->
    <div
      v-if="showWeightReminder"
      class="mx-5 mb-4 bg-white rounded-[16px] px-4 py-3.5 flex items-center gap-3 shadow-sm animate-fadeInUp delay-1"
      style="opacity:0"
    >
      <div class="w-9 h-9 rounded-full bg-ios-blue/10 flex items-center justify-center text-lg flex-shrink-0">⚖️</div>
      <NuxtLink to="/weight" class="flex-1 min-w-0">
        <p class="text-[13px] font-medium text-black leading-snug">Đã 1 tuần bạn chưa cân — cập nhật để AI tư vấn sát hơn</p>
      </NuxtLink>
      <button class="ios-press p-1 text-ios-gray3 flex-shrink-0" @click="dismissWeightReminder">
        <svg viewBox="0 0 24 24" class="w-4 h-4" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
      </button>
    </div>

    <!-- Daily tasks card -->
    <DailyTasksCard
      :meal-logged="streak?.is_logged_today ?? false"
      :streak-at-risk="showRiskBanner"
      @logged="fetchTodayStats"
    />

    <!-- Hoạt động hôm nay -->
    <NuxtLink
      to="/activities"
      class="mx-[18px] mt-3 flex items-center gap-3 bg-white rounded-[20px] px-4 py-3.5 shadow-[0_6px_16px_rgba(60,74,52,0.05)] ios-press animate-fadeInUp delay-2" style="opacity:0"
    >
      <div class="w-10 h-10 rounded-[13px] bg-ios-orange/12 flex items-center justify-center text-lg flex-shrink-0">🏃</div>
      <div class="flex-1 min-w-0">
        <p class="text-[14px] font-semibold text-calor-deep">Hoạt động hôm nay</p>
        <p class="text-[12px] text-ios-gray mt-0.5">
          <template v-if="burned > 0">Đã đốt {{ burned.toLocaleString('vi') }} kcal · Thêm buổi tập</template>
          <template v-else>Thêm buổi tập để cộng calo đốt</template>
        </p>
      </div>
      <svg viewBox="0 0 24 24" class="w-4 h-4 text-ios-gray3" fill="currentColor"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
    </NuxtLink>

    <!-- Quick-add: món hay ăn theo khung giờ hiện tại -->
    <div v-if="frequentItems.length" class="mb-4 animate-fadeInUp delay-3" style="opacity:0">
      <p class="text-[17px] font-extrabold text-black mb-2 px-5">Hay ăn giờ này 😋</p>
      <div class="flex gap-2 overflow-x-auto px-5 pb-1">
        <button
          v-for="item in frequentItems" :key="item.food_name + (item.serving ?? '')"
          class="flex-shrink-0 bg-white rounded-[14px] px-3.5 py-2.5 shadow-sm ios-press text-left"
          @click="openQuickAdd(item)"
        >
          <p class="text-[13px] font-medium text-black whitespace-nowrap">{{ item.food_name }}</p>
          <p class="text-[11px] text-ios-gray mt-0.5">{{ item.calories }} kcal</p>
        </button>
      </div>
    </div>

    <!-- Today's meals -->
    <div class="px-5 animate-fadeInUp delay-4" style="opacity:0">
      <div class="flex items-center justify-between mb-3">
        <h2 class="font-display text-[18px] font-extrabold text-black">Đã nạp hôm nay 🍜</h2>
        <NuxtLink to="/history" class="text-[13px] text-calor-green font-bold bg-calor-light rounded-full px-3 py-1 ios-press">Xem tất cả</NuxtLink>
      </div>

      <div class="bg-white rounded-[20px] overflow-hidden shadow-sm">
        <div v-if="meals.length === 0 && !loading" class="px-4 py-6 flex flex-col items-center gap-2 text-center">
          <CaloeyeCharacter mood="reminder" :size="64" />
          <p class="text-[14px] font-semibold text-black">Chưa nạp gì hôm nay 👀</p>
          <p class="text-[12px] text-ios-gray">Snap món đầu tiên để bắt đầu nào!</p>
        </div>
        <div
          v-for="(row, idx) in mealRows"
          :key="row.kind === 'group' ? 'g' + row.id : 'm' + row.id"
        >
          <!-- Cụm nhiều món cùng 1 ảnh -->
          <div v-if="row.kind === 'group'" class="flex items-start gap-3 px-4 py-3.5">
            <img
              v-if="row.image_url"
              :src="row.image_url"
              class="w-10 h-10 rounded-[10px] object-cover flex-shrink-0 mt-0.5"
              alt=""
            />
            <div class="flex-1 min-w-0">
              <p class="text-[15px] font-medium text-black">Bữa {{ row.items.length }} món</p>
              <ul class="mt-1 space-y-0.5">
                <li v-for="m in row.items" :key="m.id" class="text-[12px] text-ios-gray flex justify-between gap-2">
                  <span class="truncate">• {{ m.food_name }}</span>
                  <span class="flex-shrink-0">{{ m.calories }} kcal</span>
                </li>
              </ul>
              <p class="text-[11px] text-ios-gray mt-1">{{ row.logged_at }}</p>
            </div>
            <div class="text-right flex-shrink-0">
              <p class="text-[15px] font-semibold text-black">{{ row.calories }}</p>
              <p class="text-[11px] text-ios-gray">kcal</p>
            </div>
          </div>
          <!-- Món lẻ -->
          <div v-else class="flex items-center gap-3 px-4 py-3.5">
            <!-- Ảnh đã chụp / icon nếu nhập tay -->
            <img
              v-if="row.image_url"
              :src="row.image_url"
              class="w-10 h-10 rounded-[10px] object-cover flex-shrink-0"
              alt=""
            />
            <div v-else class="w-10 h-10 rounded-[10px] bg-ios-gray6 flex items-center justify-center text-xl flex-shrink-0">
              🍽️
            </div>
            <!-- Info -->
            <div class="flex-1 min-w-0">
              <p class="text-[15px] font-medium text-black truncate">{{ row.food_name }}</p>
              <p class="text-[12px] text-ios-gray mt-0.5">{{ row.logged_at }}{{ row.serving ? ` · ${row.serving}` : '' }}</p>
            </div>
            <!-- Calories -->
            <div class="text-right">
              <p class="text-[15px] font-semibold text-black">{{ row.calories }}</p>
              <p class="text-[11px] text-ios-gray">kcal</p>
            </div>
          </div>
          <div v-if="idx < mealRows.length - 1" class="ios-separator mx-4"/>
        </div>

        <!-- Add meal row -->
        <div class="ios-separator mx-4"/>
        <NuxtLink to="/scan" class="flex items-center gap-3 px-4 py-3.5 ios-press">
          <div class="w-10 h-10 rounded-[10px] bg-ios-blue/10 flex items-center justify-center">
            <svg viewBox="0 0 24 24" class="w-5 h-5" style="fill:var(--color-calor-green)">
              <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
            </svg>
          </div>
          <span class="text-[15px] text-ios-blue font-medium">Thêm bữa ăn</span>
        </NuxtLink>
      </div>
    </div>

    <!-- AI plan suggestion → mở trang kế hoạch ngày mai -->
    <NuxtLink
      to="/plan"
      class="mx-5 mt-4 relative overflow-hidden bg-gradient-to-br from-[var(--color-matcha-mid)] via-[var(--color-calor-green)] to-[var(--color-calor-dark)] rounded-[22px] p-4 flex gap-3 ios-press animate-fadeInUp delay-5 shadow-lg shadow-ios-purple/25"
      style="opacity:0"
    >
      <div class="absolute -bottom-8 -right-6 w-28 h-28 rounded-full bg-white/10 blur-xl pointer-events-none"/>
      <div class="w-9 h-9 rounded-full bg-white/25 flex items-center justify-center flex-shrink-0 mt-0.5">
        <svg viewBox="0 0 24 24" class="w-4 h-4" fill="white">
          <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/>
        </svg>
      </div>
      <div class="relative flex-1 min-w-0">
        <p class="text-[14px] font-extrabold text-white">Kế hoạch ngày mai ✨</p>
        <p class="text-[13px] text-white/85 mt-0.5 leading-relaxed">
          Còn <span class="font-bold text-white">{{ (goal - consumed).toLocaleString('vi') }} kcal</span> hôm nay. Để AI lên menu &amp; lịch tập cho ngày mai dựa trên data của bạn.
        </p>
        <span class="inline-flex items-center gap-1 text-[13px] text-white font-bold mt-1.5">
          Xem ngay
          <svg viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="currentColor"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
        </span>
      </div>
    </NuxtLink>
  </div>

  <NotificationPanel v-model:open="panelOpen" />

  <!-- Streak modal -->
  <StreakModal
    v-model:open="streakOpen"
    :streak="streak"
    :loading="false"
    @use-freeze="useFreeze()"
  />

  <!-- Milestone celebration -->
  <MilestoneToast
    v-if="newMilestone !== null && milestoneInfo"
    :days="newMilestone"
    :earned-token="earnedTokenAtMilestone"
    @close="newMilestone = null"
  />

  <!-- Confirm sheet: thêm nhanh món hay ăn -->
  <Teleport to="body">
    <div v-if="quickAddTarget" class="fixed inset-0 z-50 flex items-end justify-center" @click.self="quickAddTarget = null">
      <div class="absolute inset-0 bg-black/40" @click="quickAddTarget = null"/>
      <div class="relative w-full max-w-md bg-white rounded-t-[24px] px-5 pt-3 pb-8 animate-slideUpSheet">
        <div class="w-10 h-1 bg-ios-gray4 rounded-full mx-auto mb-4"/>
        <p class="text-[15px] text-ios-gray mb-1">Thêm vào hôm nay</p>
        <h2 class="text-[20px] font-bold text-black mb-1">{{ quickAddTarget.food_name }}</h2>
        <p class="text-[14px] text-ios-gray mb-5">{{ quickAddTarget.calories }} kcal<template v-if="quickAddTarget.serving"> · {{ quickAddTarget.serving }}</template></p>
        <button
          class="w-full py-3.5 bg-ios-blue text-white text-[16px] font-semibold rounded-[14px] ios-press disabled:opacity-50"
          :disabled="quickAddSaving"
          @click="confirmQuickAdd"
        >{{ quickAddSaving ? 'Đang lưu...' : 'Ghi' }}</button>
      </div>
    </div>
  </Teleport>
</template>
