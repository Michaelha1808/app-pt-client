<script setup lang="ts">
import { useMealLog } from '@/composables/useMealLog'
import { useHealthIntegration } from '@/composables/useHealthIntegration'
import { useQuickLog } from '@/composables/useQuickLog'
import { useToast } from '@/composables/useToast'
import { activityMeta } from '@/types/health'
import CaloeyeCharacter from '@/components/caloeye/Character.vue'
import ShareMealSheet from '@/components/share/ShareMealSheet.vue'
import type { ShareMealData } from '@/types/share'
import type { TimelineEntry, TimelineMeal } from '@/types/meal'
import { useAuthStore } from '@/stores/auth'

const store = useAuthStore()
const { timeline, loading, fetchTimeline, deleteLog, relogMeal } = useMealLog()
const { deleteActivity } = useHealthIntegration()
const { favorites, fetchFavorites, addFavorite, removeFavorite } = useQuickLog()
const toast = useToast()

const goal = computed(() => store.user?.calorie_goal ?? 2000)

// ── Chế độ lọc thời gian ──────────────────────────────────────────
type Mode = 'week' | 'month' | 'day'
const mode       = ref<Mode>('week')       // mặc định: theo tuần
const weekOffset = ref(0)                  // 0 = tuần hiện tại, +1 = tuần trước...
const monthStr   = ref(monthOfToday())     // YYYY-MM
const dayStr     = ref(ymd(new Date()))    // YYYY-MM-DD

function ymd(d: Date): string {
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${d.getFullYear()}-${m}-${day}`
}
function monthOfToday(): string {
  return ymd(new Date()).slice(0, 7)
}

const range = computed<{ from: string; to: string }>(() => {
  if (mode.value === 'day') return { from: dayStr.value, to: dayStr.value }
  if (mode.value === 'month') {
    const [y, m] = monthStr.value.split('-').map(Number)
    return { from: ymd(new Date(y, m - 1, 1)), to: ymd(new Date(y, m, 0)) }
  }
  // week: 7 ngày kết thúc ở (hôm nay - weekOffset*7)
  const base = new Date()
  base.setDate(base.getDate() - weekOffset.value * 7)
  const from = new Date(base)
  from.setDate(base.getDate() - 6)
  return { from: ymd(from), to: ymd(base) }
})

const rangeLabel = computed(() => {
  const f = new Date(range.value.from + 'T12:00:00')
  const t = new Date(range.value.to + 'T12:00:00')
  if (mode.value === 'day') return f.toLocaleDateString('vi-VN', { weekday: 'long', day: 'numeric', month: 'long' })
  if (mode.value === 'month') return f.toLocaleDateString('vi-VN', { month: 'long', year: 'numeric' })
  const fmt = (d: Date) => d.toLocaleDateString('vi-VN', { day: 'numeric', month: 'numeric' })
  return `${fmt(f)} – ${fmt(t)}`
})

async function reload() {
  if (!store.token) return
  await fetchTimeline(range.value.from, range.value.to)
}

watch(range, reload)
watch(mode, () => { weekOffset.value = 0 })

// ── Tổng hợp ──────────────────────────────────────────────────────
const totalIntake = computed(() => timeline.value?.total_intake ?? 0)
const totalBurned = computed(() => timeline.value?.total_burned ?? 0)
const netCal      = computed(() => totalIntake.value - totalBurned.value)
const days        = computed(() => timeline.value?.days ?? [])
const entries     = computed(() => timeline.value?.entries ?? [])
const showChart   = computed(() => mode.value !== 'day' && days.value.length > 1)

const maxCal = computed(() => Math.max(...days.value.map(d => d.intake), goal.value, 1))

// Cụm nhiều món chụp cùng 1 ảnh (log-batch): gom lại 1 dòng với danh sách món.
interface MealCluster {
  kind: 'cluster'
  id: string
  date: string
  time: string
  image_url: string | null
  meals: TimelineMeal[]
  calories: number
}
type DayItem = TimelineEntry | MealCluster

// Gom các bữa ăn cùng ảnh + cùng thời điểm thành 1 cụm; cụm chỉ 1 món → giữ nguyên dòng lẻ.
function clusterDay(items: TimelineEntry[]): DayItem[] {
  const out: DayItem[] = []
  const map = new Map<string, MealCluster>()
  for (const e of items) {
    if (e.kind === 'meal' && e.image_url) {
      const key = `${e.time}|${e.image_url}`
      let c = map.get(key)
      if (!c) {
        c = { kind: 'cluster', id: `${e.date}|${key}`, date: e.date, time: e.time, image_url: e.image_url, meals: [], calories: 0 }
        map.set(key, c)
        out.push(c)
      }
      c.meals.push(e)
      c.calories += e.calories
    } else {
      out.push(e)
    }
  }
  return out.map(it => (it.kind === 'cluster' && it.meals.length === 1) ? it.meals[0] : it)
}

// Nhóm timeline theo ngày (entries đã sắp mới→cũ ở backend), rồi gom cụm nhiều món.
const grouped = computed<{ date: string; items: DayItem[] }[]>(() => {
  const out: { date: string; items: TimelineEntry[] }[] = []
  const idx = new Map<string, TimelineEntry[]>()
  for (const e of entries.value) {
    let arr = idx.get(e.date)
    if (!arr) { arr = []; idx.set(e.date, arr); out.push({ date: e.date, items: arr }) }
    arr.push(e)
  }
  return out.map(g => ({ date: g.date, items: clusterDay(g.items) }))
})

function dayHeader(dateStr: string): string {
  const today = ymd(new Date())
  const y = new Date(); y.setDate(y.getDate() - 1)
  if (dateStr === today) return 'Hôm nay'
  if (dateStr === ymd(y)) return 'Hôm qua'
  return new Date(dateStr + 'T12:00:00').toLocaleDateString('vi-VN', { weekday: 'long', day: 'numeric', month: 'numeric' })
}

// ── Helper hiển thị ──
function mealTag(timeStr: string): string {
  const h = parseInt(timeStr.split(':')[0], 10)
  if (h < 10) return 'Sáng'
  if (h < 14) return 'Trưa'
  if (h < 17) return 'Phụ'
  return 'Tối'
}
function mealEmoji(name: string): string {
  const n = name.toLowerCase()
  if (n.includes('phở') || n.includes('bún') || n.includes('mì')) return '🍜'
  if (n.includes('cơm')) return '🍚'
  if (n.includes('gà')) return '🍗'
  if (n.includes('thịt') || n.includes('bò')) return '🥩'
  if (n.includes('cá')) return '🐟'
  if (n.includes('rau') || n.includes('salad')) return '🥗'
  if (n.includes('sinh tố') || n.includes('nước')) return '🥤'
  if (n.includes('bánh')) return '🍞'
  return '🍽'
}
function fmtActDuration(sec: number): string {
  const m = Math.round(sec / 60)
  if (m < 60) return `${m} phút`
  const h = Math.floor(m / 60)
  return `${h}h${m % 60 ? ` ${m % 60}p` : ''}`
}
function fmtActDistance(m: number | null): string | null {
  if (!m) return null
  return m >= 1000 ? `${(m / 1000).toFixed(1)} km` : `${m} m`
}

function favoriteOf(meal: TimelineMeal) {
  return favorites.value.find(f => f.food_name === meal.food_name && (f.serving ?? null) === (meal.serving ?? null)) ?? null
}

// ── Sheet xem lại phân tích món ăn ────────────────────────────────
const detail = ref<TimelineMeal | null>(null)
const cluster = ref<MealCluster | null>(null)
// Phân tích AI chung cho cả bữa (log-batch lưu cùng advice cho mọi món → lấy cái đầu tiên có).
const clusterAdvice = computed(() => cluster.value?.meals.find(m => m.ai_advice)?.ai_advice ?? null)
function openDetail(e: DayItem) {
  if (e.kind === 'meal') detail.value = e
  else if (e.kind === 'cluster') cluster.value = e
}
// Mở 1 món trong cụm → đóng sheet cụm, mở sheet chi tiết món (tái dùng mọi hành động sẵn có).
function openMealFromCluster(m: TimelineMeal) {
  cluster.value = null
  detail.value = m
}
// Xoá cả bữa (mọi món trong cụm).
async function removeCluster(c: MealCluster) {
  if (!confirm(`Xoá cả bữa ${c.meals.length} món này?`)) return
  const results = await Promise.all(c.meals.map(m => deleteLog(m.id)))
  if (results.some(Boolean)) { cluster.value = null; toast.success('Đã xóa bữa ăn'); await reload() }
  else toast.error('Không thể xóa')
}
// Ăn lại cả bữa.
async function eatAgainCluster(c: MealCluster) {
  const results = await Promise.all(c.meals.map(m => relogMeal(m.id)))
  const ok = results.some(Boolean)
  toast[ok ? 'success' : 'error'](ok ? 'Đã thêm bữa vào hôm nay' : 'Không thể ăn lại bữa này')
  if (ok) { cluster.value = null; await reload() }
}
const detailMacros = computed(() => detail.value ? [
  { label: 'Protein',  value: detail.value.protein, unit: 'g',  color: '#007AFF' },
  { label: 'Carbs',    value: detail.value.carbs,   unit: 'g',  color: '#FF9500' },
  { label: 'Chất béo', value: detail.value.fat,     unit: 'g',  color: '#FF2D55' },
  { label: 'Natri',    value: detail.value.sodium,  unit: 'mg', color: '#8E8E93' },
] : [])

async function removeMeal(m: TimelineMeal) {
  const ok = await deleteLog(m.id)
  if (ok) { detail.value = null; toast.success('Đã xóa bữa ăn'); await reload() }
  else toast.error('Không thể xóa')
}
async function eatAgain(m: TimelineMeal) {
  const ok = await relogMeal(m.id)
  toast[ok ? 'success' : 'error'](ok ? 'Đã thêm vào hôm nay' : 'Không thể ăn lại món này')
  if (ok) { detail.value = null; await reload() }
}
async function toggleFavorite(m: TimelineMeal) {
  const fav = favoriteOf(m)
  if (fav) {
    await removeFavorite(fav.id)
    toast.success(`Đã bỏ yêu thích "${m.food_name}"`)
  } else {
    const ok = await addFavorite({ meal_log_id: m.id })
    toast[ok ? 'success' : 'error'](ok ? `Đã thêm "${m.food_name}" vào yêu thích` : 'Không thể thêm')
  }
}
async function removeActivity(id: number) {
  if (!confirm('Xoá buổi tập này?')) return
  const ok = await deleteActivity(id)
  if (ok) { toast.success('Đã xoá'); await reload() }
  else toast.error('Chỉ xoá được buổi tập tự thêm')
}

// ── Chia sẻ ──
const shareOpen = ref(false)
const shareMeal = ref<ShareMealData | null>(null)
function shareLog(m: TimelineMeal) {
  const dateLabel = new Date(m.date + 'T12:00:00').toLocaleDateString('vi-VN')
  shareMeal.value = {
    food_name: m.food_name,
    serving:   m.serving,
    calories:  m.calories,
    protein:   m.protein,
    carbs:     m.carbs,
    fat:       m.fat,
    image:     m.image_url ?? null,
    logged_at: `Bữa ${mealTag(m.time).toLowerCase()} · ${m.time}, ${dateLabel}`,
    goal_percent: goal.value > 0 ? Math.round((m.calories / goal.value) * 100) : null,
  }
  shareOpen.value = true
}

onMounted(async () => {
  if (store.token) {
    fetchFavorites()
    await reload()
  }
})
</script>

<template>
  <div class="pb-4">
    <!-- Header -->
    <div class="px-5 pt-2 pb-3">
      <h1 class="text-[28px] font-bold text-black animate-fadeInUp" style="opacity:0">Lịch sử</h1>

      <!-- Bộ chọn chế độ -->
      <div class="mt-3 bg-ios-gray5 rounded-[10px] p-1 flex animate-fadeInUp delay-1" style="opacity:0">
        <button
          v-for="m in [{ key: 'week', label: 'Tuần' }, { key: 'month', label: 'Tháng' }, { key: 'day', label: 'Ngày' }]"
          :key="m.key"
          class="flex-1 py-1.5 rounded-[8px] text-[14px] font-semibold transition-all"
          :class="mode === m.key ? 'bg-white text-black shadow-sm' : 'text-ios-gray'"
          @click="mode = m.key as Mode"
        >{{ m.label }}</button>
      </div>
    </div>

    <!-- Auth gate -->
    <div v-if="!store.token" class="mx-5 bg-white rounded-[18px] p-6 text-center animate-fadeInUp" style="opacity:0">
      <p class="text-[32px] mb-2">📋</p>
      <p class="text-[17px] font-semibold text-black mb-1">Đăng nhập để xem lịch sử</p>
      <p class="text-[13px] text-ios-gray mb-4">Lịch sử ăn uống & tập luyện được lưu khi bạn đăng nhập</p>
      <NuxtLink to="/auth/login" class="inline-block bg-ios-blue text-white text-[15px] font-semibold px-6 py-2.5 rounded-[12px] ios-press">
        Đăng nhập
      </NuxtLink>
    </div>

    <template v-else>
      <!-- Thanh điều hướng khoảng thời gian -->
      <div class="px-5 mb-3 animate-fadeInUp delay-1" style="opacity:0">
        <div class="bg-white rounded-[14px] px-3 py-2.5 flex items-center gap-2">
          <!-- Tuần: mũi tên qua lại -->
          <template v-if="mode === 'week'">
            <button class="w-8 h-8 rounded-full bg-ios-gray6 flex items-center justify-center ios-press" @click="weekOffset++">
              <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#8E8E93"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
            </button>
            <p class="flex-1 text-center text-[15px] font-semibold text-black capitalize">{{ rangeLabel }}</p>
            <button
              class="w-8 h-8 rounded-full flex items-center justify-center ios-press"
              :class="weekOffset === 0 ? 'bg-ios-gray6 opacity-40' : 'bg-ios-gray6'"
              :disabled="weekOffset === 0"
              @click="weekOffset--"
            >
              <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#8E8E93"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/></svg>
            </button>
          </template>
          <!-- Tháng: chọn tháng -->
          <template v-else-if="mode === 'month'">
            <span class="text-[15px] font-semibold text-black flex-1 capitalize">{{ rangeLabel }}</span>
            <input type="month" v-model="monthStr" class="text-[14px] text-ios-blue font-medium bg-ios-gray6 rounded-[8px] px-2 py-1.5 outline-none"/>
          </template>
          <!-- Ngày: chọn ngày cụ thể -->
          <template v-else>
            <span class="text-[15px] font-semibold text-black flex-1 capitalize">{{ rangeLabel }}</span>
            <input type="date" v-model="dayStr" class="text-[14px] text-ios-blue font-medium bg-ios-gray6 rounded-[8px] px-2 py-1.5 outline-none"/>
          </template>
        </div>
      </div>

      <!-- Summary -->
      <div class="mx-5 mb-4 bg-white rounded-[18px] px-5 py-4 shadow-sm animate-fadeInUp delay-2" style="opacity:0">
        <div class="grid grid-cols-3 gap-2 text-center">
          <div>
            <p class="text-[22px] font-bold text-ios-green leading-tight">{{ totalIntake.toLocaleString('vi') }}</p>
            <p class="text-[11px] text-ios-gray">Nạp (kcal)</p>
          </div>
          <div>
            <p class="text-[22px] font-bold text-ios-orange leading-tight">{{ totalBurned.toLocaleString('vi') }}</p>
            <p class="text-[11px] text-ios-gray">Đốt (kcal)</p>
          </div>
          <div>
            <p class="text-[22px] font-bold text-black leading-tight">{{ netCal.toLocaleString('vi') }}</p>
            <p class="text-[11px] text-ios-gray">Nạp ròng</p>
          </div>
        </div>
        <div v-if="timeline && (timeline.total_protein || timeline.total_carbs || timeline.total_fat)" class="flex justify-center gap-5 mt-3 pt-3 border-t-hairline border-ios-gray5">
          <div class="text-center"><p class="text-[13px] font-semibold text-ios-blue">{{ timeline.total_protein }}g</p><p class="text-[10px] text-ios-gray">Protein</p></div>
          <div class="text-center"><p class="text-[13px] font-semibold text-ios-orange">{{ timeline.total_carbs }}g</p><p class="text-[10px] text-ios-gray">Carbs</p></div>
          <div class="text-center"><p class="text-[13px] font-semibold text-ios-red">{{ timeline.total_fat }}g</p><p class="text-[10px] text-ios-gray">Chất béo</p></div>
        </div>
      </div>

      <!-- Bar chart theo ngày -->
      <div v-if="showChart" class="mx-5 mb-4 bg-white rounded-[18px] p-4 animate-fadeInUp delay-2" style="opacity:0">
        <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-3">Calo nạp theo ngày</p>
        <div class="flex items-end gap-1.5 h-24 overflow-x-auto">
          <div v-for="d in days" :key="d.date" class="flex-1 min-w-[16px] flex flex-col items-center gap-1">
            <div class="w-full flex-1 flex items-end">
              <div
                class="w-full rounded-t-[4px] transition-all duration-700"
                :style="`height: ${d.intake > 0 ? Math.max(6, Math.round((d.intake / maxCal) * 100)) : 0}%; background: ${d.intake > goal ? '#FF3B30' : '#18A874'}`"
              />
            </div>
            <span class="text-[9px] text-ios-gray whitespace-nowrap">{{ days.length > 10 ? d.day_num : d.day_label }}</span>
          </div>
        </div>
        <div class="flex justify-between text-[12px] text-ios-gray mt-2">
          <span>TB nạp: {{ days.filter(d => d.intake > 0).length ? Math.round(days.reduce((s, d) => s + d.intake, 0) / days.filter(d => d.intake > 0).length) : 0 }} kcal</span>
          <span>Mục tiêu: {{ goal.toLocaleString() }} kcal</span>
        </div>
      </div>

      <!-- Nút thêm nhanh -->
      <div class="px-5 mb-4 flex gap-3 animate-fadeInUp delay-3" style="opacity:0">
        <NuxtLink to="/scan" class="flex-1 bg-white rounded-[14px] py-3 flex items-center justify-center gap-2 shadow-sm ios-press">
          <span class="text-lg">🍽</span><span class="text-[14px] font-semibold text-ios-green">Thêm bữa ăn</span>
        </NuxtLink>
        <NuxtLink to="/activities" class="flex-1 bg-white rounded-[14px] py-3 flex items-center justify-center gap-2 shadow-sm ios-press">
          <span class="text-lg">🏋️</span><span class="text-[14px] font-semibold text-ios-orange">Thêm buổi tập</span>
        </NuxtLink>
      </div>

      <!-- Timeline gộp -->
      <div class="px-5">
        <div v-if="loading && !timeline" class="flex justify-center py-8">
          <div class="w-8 h-8 rounded-full border-2 border-ios-blue border-t-transparent animate-spin"/>
        </div>

        <div v-else-if="entries.length === 0" class="bg-white rounded-[18px] p-6 flex flex-col items-center gap-2 text-center">
          <CaloeyeCharacter mood="reminder" :size="72" />
          <p class="text-[15px] font-medium text-black">Chưa có hoạt động nào</p>
          <p class="text-[13px] text-ios-gray">Quét món ăn hoặc thêm buổi tập để bắt đầu ghi lại</p>
        </div>

        <template v-else>
          <div v-for="group in grouped" :key="group.date" class="mb-4">
            <p class="text-[13px] font-semibold text-ios-gray mb-2 px-1 capitalize">{{ dayHeader(group.date) }}</p>
            <div class="bg-white rounded-[18px] overflow-hidden shadow-sm">
              <template v-for="(e, idx) in group.items" :key="e.kind + '-' + e.id">
                <!-- Món ăn -->
                <div
                  v-if="e.kind === 'meal'"
                  class="flex items-center gap-3 px-4 py-3.5 ios-press"
                  @click="openDetail(e)"
                >
                  <img v-if="e.image_url" :src="e.image_url" class="w-10 h-10 rounded-[10px] object-cover flex-shrink-0" alt=""/>
                  <div v-else class="w-10 h-10 rounded-[10px] bg-ios-gray6 flex items-center justify-center text-xl flex-shrink-0">{{ mealEmoji(e.food_name) }}</div>
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-1.5 flex-wrap">
                      <p class="text-[15px] font-medium text-black truncate">{{ e.food_name }}</p>
                      <span class="text-[10px] font-semibold text-ios-orange bg-ios-orange/10 rounded-full px-1.5 py-0.5 flex-shrink-0">{{ mealTag(e.time) }}</span>
                    </div>
                    <p class="text-[12px] text-ios-gray">{{ e.time }}{{ e.serving ? ` · ${e.serving}` : '' }}<span v-if="e.ai_advice"> · 📝 có phân tích</span></p>
                  </div>
                  <div class="text-right flex-shrink-0">
                    <p class="text-[15px] font-semibold text-ios-green">+{{ e.calories }}</p>
                    <p class="text-[11px] text-ios-gray">kcal</p>
                  </div>
                </div>
                <!-- Cụm nhiều món cùng 1 ảnh (mâm/bàn tiệc) -->
                <div
                  v-else-if="e.kind === 'cluster'"
                  class="flex items-start gap-3 px-4 py-3.5 ios-press"
                  @click="openDetail(e)"
                >
                  <img v-if="e.image_url" :src="e.image_url" class="w-10 h-10 rounded-[10px] object-cover flex-shrink-0 mt-0.5" alt=""/>
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-1.5">
                      <p class="text-[15px] font-medium text-black">Bữa {{ e.meals.length }} món</p>
                      <span class="text-[10px] font-semibold text-ios-orange bg-ios-orange/10 rounded-full px-1.5 py-0.5 flex-shrink-0">{{ mealTag(e.time) }}</span>
                    </div>
                    <ul class="mt-1 space-y-0.5">
                      <li v-for="m in e.meals" :key="m.id" class="text-[12px] text-ios-gray flex justify-between gap-2">
                        <span class="truncate">• {{ m.food_name }}</span>
                        <span class="flex-shrink-0">{{ m.calories }} kcal</span>
                      </li>
                    </ul>
                    <p class="text-[11px] text-ios-gray mt-1">{{ e.time }}</p>
                  </div>
                  <div class="text-right flex-shrink-0">
                    <p class="text-[15px] font-semibold text-ios-green">+{{ e.calories }}</p>
                    <p class="text-[11px] text-ios-gray">kcal</p>
                  </div>
                </div>
                <!-- Buổi tập -->
                <div v-else class="flex items-center gap-3 px-4 py-3.5">
                  <div class="w-10 h-10 rounded-[10px] bg-ios-orange/10 flex items-center justify-center text-xl flex-shrink-0">{{ activityMeta(e.type).emoji }}</div>
                  <div class="flex-1 min-w-0">
                    <p class="text-[15px] font-medium text-black truncate">
                      {{ e.name || activityMeta(e.type).label }}
                      <span v-if="e.source === 'manual'" class="text-[11px] text-ios-gray font-normal">· tự thêm</span>
                    </p>
                    <p class="text-[12px] text-ios-gray">{{ e.time }} · {{ fmtActDuration(e.duration_seconds) }}<template v-if="fmtActDistance(e.distance_meters)"> · {{ fmtActDistance(e.distance_meters) }}</template></p>
                  </div>
                  <div class="text-right flex-shrink-0">
                    <p class="text-[15px] font-semibold text-ios-orange">-{{ e.calories ?? 0 }}</p>
                    <p class="text-[11px] text-ios-gray">kcal</p>
                  </div>
                  <button v-if="e.source === 'manual'" class="ios-press p-1 flex-shrink-0 text-ios-gray3" @click.stop="removeActivity(e.id)">
                    <svg viewBox="0 0 24 24" class="w-5 h-5" fill="currentColor"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                  </button>
                </div>
                <div v-if="idx < group.items.length - 1" class="ios-separator mx-4"/>
              </template>
            </div>
          </div>
          <p class="text-[12px] text-ios-gray text-center mt-1">Nhấn vào bữa ăn để xem lại phân tích dinh dưỡng & lời khuyên AI</p>
        </template>
      </div>
    </template>

    <!-- ── Sheet xem lại phân tích món ăn ── -->
    <Teleport to="body">
      <div v-if="detail" class="fixed inset-0 z-50 flex items-end justify-center" @click.self="detail = null">
        <div class="absolute inset-0 bg-black/40" @click="detail = null"/>
        <div class="relative w-full max-w-[430px] bg-[#F2F2F7] rounded-t-[24px] max-h-[88vh] overflow-y-auto animate-slideUpSheet">
          <div class="sticky top-0 bg-[#F2F2F7] pt-3 px-5 pb-2 z-10">
            <div class="w-10 h-1 bg-ios-gray4 rounded-full mx-auto mb-2"/>
            <div class="flex items-center justify-between">
              <p class="text-[13px] text-ios-gray">{{ dayHeader(detail.date) }} · {{ detail.time }}</p>
              <button class="w-8 h-8 rounded-full bg-ios-gray5 flex items-center justify-center ios-press" @click="detail = null">
                <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#8E8E93"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
              </button>
            </div>
          </div>

          <div class="px-5 pb-8">
            <!-- Ảnh -->
            <div class="w-full h-40 rounded-[18px] overflow-hidden mb-4 bg-ios-gray6 flex items-center justify-center">
              <img v-if="detail.image_url" :src="detail.image_url" class="w-full h-full object-cover" alt=""/>
              <span v-else class="text-6xl">{{ mealEmoji(detail.food_name) }}</span>
            </div>

            <!-- Tên + calo -->
            <div class="bg-white rounded-[18px] px-5 py-4 mb-4 shadow-sm">
              <div class="flex items-start justify-between gap-3">
                <div class="flex-1 min-w-0">
                  <h2 class="text-[22px] font-bold text-black">{{ detail.food_name }}</h2>
                  <p v-if="detail.serving" class="text-[13px] text-ios-gray mt-1">{{ detail.serving }}</p>
                </div>
                <div class="text-right flex-shrink-0">
                  <p class="text-[36px] font-bold text-ios-green leading-none">{{ detail.calories }}</p>
                  <p class="text-[13px] text-ios-gray">kcal</p>
                </div>
              </div>
              <div class="grid grid-cols-4 gap-2 mt-4 pt-4 border-t-hairline border-ios-gray5">
                <div v-for="m in detailMacros" :key="m.label" class="flex flex-col items-center">
                  <div class="w-8 h-8 rounded-full flex items-center justify-center mb-1" :style="`background: ${m.color}18`">
                    <div class="w-2.5 h-2.5 rounded-full" :style="`background: ${m.color}`"/>
                  </div>
                  <p class="text-[13px] font-semibold text-black">{{ m.value }}<span class="text-[10px] text-ios-gray">{{ m.unit }}</span></p>
                  <p class="text-[10px] text-ios-gray text-center leading-tight">{{ m.label }}</p>
                </div>
              </div>
            </div>

            <!-- Lời khuyên AI đã lưu -->
            <div v-if="detail.ai_advice" class="bg-white rounded-[18px] px-5 py-4 mb-4 shadow-sm">
              <div class="flex items-center gap-2 mb-3">
                <div class="w-6 h-6 rounded-full bg-calor-green flex items-center justify-center">
                  <svg viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="white"><path d="M12 2a5 5 0 110 10A5 5 0 0112 2zm0 12c5.33 0 8 2.67 8 4v2H4v-2c0-1.33 2.67-4 8-4z"/></svg>
                </div>
                <p class="text-[13px] font-semibold text-black">Lời khuyên từ AI</p>
              </div>
              <p class="text-[14px] text-black/80 leading-relaxed whitespace-pre-wrap">{{ detail.ai_advice }}</p>
            </div>
            <div v-else class="bg-ios-gray6 rounded-[14px] px-4 py-3 mb-4 text-center">
              <p class="text-[13px] text-ios-gray">Bữa ăn này chưa lưu phần phân tích AI</p>
            </div>

            <!-- Hành động -->
            <div class="flex gap-2">
              <button class="flex-1 py-3 rounded-[12px] bg-ios-gray5 text-black text-[14px] font-semibold ios-press flex items-center justify-center gap-1.5" @click="toggleFavorite(detail)">
                <span>{{ favoriteOf(detail) ? '⭐' : '☆' }}</span> Yêu thích
              </button>
              <button class="flex-1 py-3 rounded-[12px] bg-ios-blue text-white text-[14px] font-semibold ios-press" @click="eatAgain(detail)">Ăn lại</button>
              <button class="flex-1 py-3 rounded-[12px] bg-calor-green text-white text-[14px] font-semibold ios-press" @click="shareLog(detail)">Chia sẻ</button>
            </div>
            <button class="w-full mt-2 py-3 rounded-[12px] text-ios-red text-[14px] font-semibold ios-press" @click="removeMeal(detail)">Xóa bữa ăn</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ── Sheet cụm nhiều món (mâm/bàn tiệc cùng 1 ảnh) ── -->
    <Teleport to="body">
      <div v-if="cluster" class="fixed inset-0 z-50 flex items-end justify-center" @click.self="cluster = null">
        <div class="absolute inset-0 bg-black/40" @click="cluster = null"/>
        <div class="relative w-full max-w-[430px] bg-[#F2F2F7] rounded-t-[24px] max-h-[88vh] overflow-y-auto animate-slideUpSheet">
          <div class="sticky top-0 bg-[#F2F2F7] pt-3 px-5 pb-2 z-10">
            <div class="w-10 h-1 bg-ios-gray4 rounded-full mx-auto mb-2"/>
            <div class="flex items-center justify-between">
              <p class="text-[13px] text-ios-gray">{{ dayHeader(cluster.date) }} · {{ cluster.time }} · {{ cluster.meals.length }} món</p>
              <button class="w-8 h-8 rounded-full bg-ios-gray5 flex items-center justify-center ios-press" @click="cluster = null">
                <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#8E8E93"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
              </button>
            </div>
          </div>

          <div class="px-5 pb-8">
            <!-- Ảnh chung -->
            <div class="w-full h-40 rounded-[18px] overflow-hidden mb-4 bg-ios-gray6 flex items-center justify-center">
              <img v-if="cluster.image_url" :src="cluster.image_url" class="w-full h-full object-cover" alt=""/>
              <span v-else class="text-6xl">🍽</span>
            </div>

            <!-- Danh sách món trong bữa (nhấn để xem chi tiết từng món) -->
            <div class="bg-white rounded-[18px] overflow-hidden shadow-sm mb-3">
              <template v-for="(m, i) in cluster.meals" :key="m.id">
                <button class="w-full flex items-center gap-3 px-4 py-3 ios-press text-left" @click="openMealFromCluster(m)">
                  <div class="w-8 h-8 rounded-[8px] bg-ios-gray6 flex items-center justify-center text-lg flex-shrink-0">{{ mealEmoji(m.food_name) }}</div>
                  <div class="flex-1 min-w-0">
                    <p class="text-[14px] font-medium text-black truncate">{{ m.food_name }}</p>
                    <p v-if="m.serving" class="text-[12px] text-ios-gray truncate">{{ m.serving }}</p>
                  </div>
                  <p class="text-[14px] font-semibold text-ios-green flex-shrink-0">+{{ m.calories }}</p>
                  <svg viewBox="0 0 24 24" class="w-4 h-4 flex-shrink-0" fill="#C7C7CC"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/></svg>
                </button>
                <div v-if="i < cluster.meals.length - 1" class="ios-separator mx-4"/>
              </template>
            </div>

            <!-- Tổng calo cả bữa -->
            <div class="bg-white rounded-[14px] px-4 py-3 mb-4 flex items-center justify-between shadow-sm">
              <span class="text-[13px] font-semibold text-ios-gray">Tổng cả bữa</span>
              <span class="text-[17px] font-bold text-ios-green">{{ cluster.calories.toLocaleString('vi') }} kcal</span>
            </div>

            <!-- Phân tích AI của cả bữa (lưu 1 lần khi ghi nhận) -->
            <div v-if="clusterAdvice" class="bg-white rounded-[18px] px-5 py-4 mb-4 shadow-sm">
              <div class="flex items-center gap-2 mb-3">
                <div class="w-6 h-6 rounded-full bg-calor-green flex items-center justify-center">
                  <svg viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="white"><path d="M12 2a5 5 0 110 10A5 5 0 0112 2zm0 12c5.33 0 8 2.67 8 4v2H4v-2c0-1.33 2.67-4 8-4z"/></svg>
                </div>
                <p class="text-[13px] font-semibold text-black">Phân tích bữa ăn từ AI</p>
              </div>
              <p class="text-[14px] text-black/80 leading-relaxed whitespace-pre-wrap">{{ clusterAdvice }}</p>
            </div>

            <!-- Hành động cả bữa -->
            <div class="flex gap-2">
              <button class="flex-1 py-3 rounded-[12px] bg-ios-blue text-white text-[14px] font-semibold ios-press" @click="eatAgainCluster(cluster)">Ăn lại cả bữa</button>
            </div>
            <button class="w-full mt-2 py-3 rounded-[12px] text-ios-red text-[14px] font-semibold ios-press" @click="removeCluster(cluster)">Xóa cả bữa</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>

  <ShareMealSheet v-model:open="shareOpen" :meal="shareMeal" />
</template>
