<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useWeight, type WeightRange } from '@/composables/useWeight'
import { useToast } from '@/composables/useToast'
import { useAuth } from '@/composables/useAuth'

const router = useRouter()
const toast  = useToast()
const { user } = useAuth()
const { history, loading, saving, fetchHistory, logWeight, deleteEntry, applyGoal } = useWeight()

const DISMISS_KEY  = 'weight_goal_suggestion_dismissed_at'
const DISMISS_DAYS = 14

const range = ref<WeightRange>(30)
const showSheet = ref(false)
const weightInput = ref('')
const noteInput = ref('')
const suggestionDismissed = ref(isSuggestionDismissed())

function isSuggestionDismissed(): boolean {
  const raw = localStorage.getItem(DISMISS_KEY)
  if (!raw) return false
  const days = (Date.now() - Number(raw)) / (1000 * 60 * 60 * 24)
  return days < DISMISS_DAYS
}

function dismissSuggestion() {
  localStorage.setItem(DISMISS_KEY, String(Date.now()))
  suggestionDismissed.value = true
}

async function selectRange(r: WeightRange) {
  range.value = r
  await fetchHistory(r)
}

const entries = computed(() => history.value?.entries ?? [])
const entriesDesc = computed(() => [...entries.value].reverse())
const trend = computed(() => history.value?.trend ?? null)
const bmi = computed(() => history.value?.bmi ?? null)
const goalSuggestion = computed(() => suggestionDismissed.value ? null : history.value?.goal_suggestion ?? null)
const currentWeight = computed(() => trend.value?.current_weight_kg ?? user.value?.weight_kg ?? null)

const todayStr = localDateStr()
const hasTodayEntry = computed(() => entries.value.some(e => e.logged_date === todayStr))

const bmiLabelColor = computed(() => {
  if (!bmi.value) return '#8a9a7d'
  const v = bmi.value.value
  if (v < 18.5) return '#32ADE6'
  if (v < 25)   return 'var(--color-calor-green)'
  if (v < 30)   return '#FF9500'
  return '#c96a6a'
})

// ── Biểu đồ SVG ──
const CHART_W = 300
const CHART_H = 120
const CHART_PAD = 8

const rangeStart = computed(() => {
  const d = new Date()
  d.setHours(0, 0, 0, 0)
  d.setDate(d.getDate() - (range.value - 1))
  return d.getTime()
})
const rangeEnd = computed(() => {
  const d = new Date()
  d.setHours(0, 0, 0, 0)
  return d.getTime()
})

function xFor(dateStr: string): number {
  const t = new Date(dateStr + 'T00:00:00').getTime()
  const span = rangeEnd.value - rangeStart.value || 1
  const pct = Math.min(1, Math.max(0, (t - rangeStart.value) / span))
  return CHART_PAD + pct * (CHART_W - CHART_PAD * 2)
}

const weightBounds = computed(() => {
  const values = entries.value.map(e => e.weight_kg)
  if (values.length === 0) return { min: 0, max: 1 }
  const min = Math.min(...values)
  const max = Math.max(...values)
  return max === min ? { min: min - 1, max: max + 1 } : { min: min - 0.5, max: max + 0.5 }
})

function yFor(weight: number): number {
  const { min, max } = weightBounds.value
  const pct = (weight - min) / (max - min || 1)
  return CHART_H - CHART_PAD - pct * (CHART_H - CHART_PAD * 2)
}

const rawPoints = computed(() =>
  entries.value.map(e => `${xFor(e.logged_date)},${yFor(e.weight_kg)}`).join(' '),
)

// Trung bình trượt 7 ngày tại mỗi điểm (chỉ dựa trên các bản ghi thực tế trong cửa sổ 7 ngày)
const avgPoints = computed(() => {
  const list = entries.value
  return list.map((e, i) => {
    const endT = new Date(e.logged_date + 'T00:00:00').getTime()
    const startT = endT - 6 * 86400000
    const window = list.filter((o, j) => {
      if (j > i) return false
      const t = new Date(o.logged_date + 'T00:00:00').getTime()
      return t >= startT && t <= endT
    })
    const avg = window.reduce((s, o) => s + o.weight_kg, 0) / window.length
    return `${xFor(e.logged_date)},${yFor(avg)}`
  }).join(' ')
})

const lastPoint = computed(() => {
  if (entries.value.length === 0) return null
  const last = entries.value[entries.value.length - 1]
  return { x: xFor(last.logged_date), y: yFor(last.weight_kg) }
})

// Đường vùng (area) tô gradient dưới đường cân nặng
const areaPath = computed(() => {
  const pts = entries.value.map(e => `${xFor(e.logged_date)},${yFor(e.weight_kg)}`)
  if (!pts.length) return ''
  const first = pts[0].split(',')[0]
  const last  = pts[pts.length - 1].split(',')[0]
  const base  = CHART_H - CHART_PAD
  return `M${pts.join(' L')} L${last},${base} L${first},${base} Z`
})

function openSheet() {
  weightInput.value = currentWeight.value ? String(currentWeight.value) : ''
  noteInput.value = ''
  showSheet.value = true
}

async function submitWeight() {
  const w = parseFloat(weightInput.value)
  if (!w || w < 20 || w > 500) { toast.error('Cân nặng không hợp lệ'); return }

  const suggestion = await logWeight({ weight_kg: w, note: noteInput.value || undefined })
  showSheet.value = false
  toast.success('Đã ghi cân nặng')

  if (suggestion) suggestionDismissed.value = false
}

async function removeEntry(id: number) {
  if (!confirm('Xoá bản ghi cân nặng này?')) return
  await deleteEntry(id)
  toast.success('Đã xoá')
}

async function acceptGoal(suggested: number) {
  await applyGoal(suggested)
  suggestionDismissed.value = true
  toast.success(`Đã cập nhật mục tiêu: ${suggested} kcal`)
}

function fmtDate(dateStr: string): string {
  return new Date(dateStr + 'T12:00:00').toLocaleDateString('vi-VN', { day: 'numeric', month: 'short' })
}

onMounted(() => {
  fetchHistory(range.value)
})
</script>

<template>
  <div class="pb-24">
    <!-- Header -->
    <div class="flex items-center gap-2 px-4 pt-2 pb-2">
      <button class="ios-press p-1 -ml-1" @click="router.back()">
        <svg viewBox="0 0 24 24" class="w-6 h-6" style="fill:var(--color-calor-dark)">
          <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
        </svg>
      </button>
      <h1 class="font-display text-[17px] font-bold text-calor-deep">Cân nặng</h1>
    </div>

    <div v-if="loading && !history" class="mx-4 mb-4 bg-white rounded-[18px] h-40 animate-pulse shadow-sm"/>

    <template v-else>
      <!-- Big focal number -->
      <div class="text-center mt-3">
        <p class="text-[11px] tracking-[0.08em] uppercase text-ios-gray">Hiện tại</p>
        <div class="flex items-baseline justify-center gap-1.5">
          <span class="font-display text-[64px] font-bold text-calor-deep leading-none tabular-nums">{{ currentWeight ?? '—' }}</span>
          <span class="font-display text-[18px] font-semibold text-ios-gray">kg</span>
        </div>
        <span v-if="trend" class="inline-flex items-center gap-1 mt-1.5 rounded-full bg-[var(--color-calor-light)] text-calor-dark text-[12px] font-medium px-3 py-1">
          {{ trend.delta_kg <= 0 ? '▼' : '▲' }} {{ Math.abs(trend.delta_kg) }} kg / {{ range }} ngày
        </span>
      </div>

      <!-- Biểu đồ vùng -->
      <div class="mx-4 mt-4 bg-white rounded-[22px] p-4 shadow-[0_8px_22px_rgba(60,74,52,0.06)]">
        <div class="flex justify-between items-center mb-1.5">
          <span class="font-display text-[13px] font-bold text-calor-deep">Xu hướng</span>
          <div class="flex gap-1.5">
            <button
              v-for="r in [30, 90, 180] as WeightRange[]" :key="r"
              class="rounded-full text-[10px] font-semibold px-2.5 py-1 transition-colors"
              :class="range === r ? 'bg-matcha text-white' : 'bg-[var(--color-line)] text-ios-gray'"
              @click="selectRange(r)"
            >{{ r }}N</button>
          </div>
        </div>
        <div v-if="entries.length === 0" class="py-8 text-center">
          <p class="text-[32px] mb-2">⚖️</p>
          <p class="text-[14px] text-ios-gray">Chưa có dữ liệu cân nặng trong khoảng này</p>
        </div>
        <svg v-else :viewBox="`0 0 ${CHART_W} ${CHART_H}`" class="w-full h-32">
          <defs>
            <linearGradient id="wfill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0" style="stop-color:var(--color-calor-green)" stop-opacity="0.28"/>
              <stop offset="1" style="stop-color:var(--color-calor-green)" stop-opacity="0"/>
            </linearGradient>
          </defs>
          <path :d="areaPath" fill="url(#wfill)"/>
          <polyline :points="avgPoints" fill="none" stroke="#8a9a7d" stroke-width="1.5" stroke-opacity="0.4" stroke-linejoin="round" stroke-linecap="round"/>
          <polyline :points="rawPoints" fill="none" style="stroke:var(--color-calor-dark)" stroke-width="2.6" stroke-linejoin="round" stroke-linecap="round"/>
          <circle v-if="lastPoint" :cx="lastPoint.x" :cy="lastPoint.y" r="4.5" style="fill:var(--color-calor-dark)"/>
        </svg>
      </div>

      <!-- Stat tiles -->
      <div class="mx-4 mt-3 flex gap-2.5">
        <div class="flex-1 bg-white rounded-[16px] py-3 text-center shadow-[0_6px_16px_rgba(60,74,52,0.05)]">
          <p class="font-display text-[18px] font-bold text-calor-deep tabular-nums">{{ bmi ? bmi.value : '—' }}</p>
          <p class="text-[10px] text-ios-gray">BMI<template v-if="bmi"> · {{ bmi.label }}</template></p>
        </div>
        <div class="flex-1 bg-white rounded-[16px] py-3 text-center shadow-[0_6px_16px_rgba(60,74,52,0.05)]">
          <p class="font-display text-[18px] font-bold tabular-nums" :style="`color:${trend && trend.weekly_rate_kg <= 0 ? 'var(--color-calor-dark)' : '#d98c5f'}`">{{ trend ? trend.weekly_rate_kg : '—' }}</p>
          <p class="text-[10px] text-ios-gray">kg / tuần</p>
        </div>
      </div>

      <!-- Nút ghi cân nặng -->
      <div class="mx-4 mt-3.5">
        <button
          class="w-full h-[50px] bg-matcha text-white font-display text-[15px] font-bold rounded-[15px] shadow-lg shadow-matcha/35 ios-press"
          @click="openSheet"
        >⚖️ Ghi cân nặng hôm nay</button>
      </div>

      <!-- Đề xuất mục tiêu (AI tip) -->
      <div v-if="goalSuggestion" class="mx-4 mt-3 rounded-[16px] border border-[#f0dfc9] bg-[#fdf6ec] p-4">
        <div class="flex gap-2.5">
          <span class="text-xl flex-shrink-0">💡</span>
          <div class="flex-1 min-w-0">
            <p class="text-[13px] text-[#4a5545] leading-relaxed">{{ goalSuggestion.reason }}. Gợi ý mục tiêu mới <b class="text-calor-deep">{{ goalSuggestion.suggested_goal }} kcal</b> <span class="text-ios-gray">(hiện tại {{ goalSuggestion.current_goal }})</span>.</p>
            <div class="flex gap-2 mt-3">
              <button class="flex-1 py-2.5 bg-matcha text-white text-[14px] font-semibold rounded-[10px] ios-press" @click="acceptGoal(goalSuggestion.suggested_goal)">Áp dụng</button>
              <button class="flex-1 py-2.5 bg-white text-ios-gray text-[14px] font-semibold rounded-[10px] ios-press" @click="dismissSuggestion">Bỏ qua</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Danh sách bản ghi -->
      <div class="mx-4 mt-5">
        <p class="text-[13px] font-semibold text-ios-gray mb-2 px-1">Lịch sử ghi cân</p>
        <div v-if="entriesDesc.length === 0" class="bg-white rounded-[16px] px-4 py-6 text-center text-[13px] text-ios-gray shadow-sm">
          Chưa có bản ghi nào
        </div>
        <div v-else class="bg-white rounded-[16px] overflow-hidden shadow-sm">
          <div v-for="(e, idx) in entriesDesc" :key="e.id">
            <div class="flex items-center gap-3 px-4 py-3.5">
              <div class="flex-1 min-w-0">
                <p class="text-[15px] font-medium text-black">{{ e.weight_kg }} kg</p>
                <p class="text-[12px] text-ios-gray mt-0.5">{{ fmtDate(e.logged_date) }}<template v-if="e.note"> · {{ e.note }}</template></p>
              </div>
              <button class="ios-press p-1 text-ios-gray3" @click="removeEntry(e.id)">
                <svg viewBox="0 0 24 24" class="w-5 h-5" fill="currentColor"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
              </button>
            </div>
            <div v-if="idx < entriesDesc.length - 1" class="ios-separator mx-4"/>
          </div>
        </div>
      </div>
    </template>

    <!-- Bottom sheet ghi cân nặng -->
    <Teleport to="body">
      <div v-if="showSheet" class="fixed inset-0 z-50 flex items-end justify-center" @click.self="showSheet = false">
        <div class="absolute inset-0 bg-black/40" @click="showSheet = false"/>
        <div class="relative w-full max-w-md bg-white rounded-t-[24px] px-5 pt-3 pb-8 animate-slideUpSheet">
          <div class="w-10 h-1 bg-ios-gray4 rounded-full mx-auto mb-4"/>
          <h2 class="text-[18px] font-semibold text-black mb-4">Ghi cân nặng</h2>

          <p class="text-[13px] font-medium text-ios-gray mb-2">Cân nặng (kg)</p>
          <input
            v-model="weightInput" type="number" min="20" max="500" step="0.1" placeholder="65.0"
            class="w-full py-3 px-3 mb-4 rounded-[10px] bg-ios-gray6 text-[18px] font-semibold text-center focus:outline-none focus:ring-1 focus:ring-ios-blue"
          />

          <p class="text-[13px] font-medium text-ios-gray mb-2">Ghi chú (tuỳ chọn)</p>
          <input
            v-model="noteInput" type="text" maxlength="200" placeholder="sáng, chưa ăn..."
            class="w-full py-2.5 px-3 mb-2 rounded-[10px] bg-ios-gray6 text-[14px] focus:outline-none focus:ring-1 focus:ring-ios-blue"
          />

          <button
            class="w-full mt-4 py-3.5 bg-ios-blue text-white text-[16px] font-semibold rounded-[14px] ios-press disabled:opacity-50"
            :disabled="saving"
            @click="submitWeight"
          >
            {{ saving ? 'Đang lưu...' : (hasTodayEntry ? 'Ghi đè hôm nay' : 'Lưu') }}
          </button>
        </div>
      </div>
    </Teleport>
  </div>
</template>
