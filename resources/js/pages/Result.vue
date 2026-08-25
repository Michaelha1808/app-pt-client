<script setup lang="ts">
import AdvisorySource from '@/components/common/AdvisorySource.vue'
import CaloeyeCharacter from '@/components/caloeye/Character.vue'
import GuestGateModal from '@/components/common/GuestGateModal.vue'
import FoodEditSheet from '@/components/food/FoodEditSheet.vue'
import type { FoodEditValues } from '@/components/food/FoodEditSheet.vue'
import ShareMealSheet from '@/components/share/ShareMealSheet.vue'
import type { ShareMealData } from '@/types/share'
import { useFoodAnalysis } from '@/composables/useFoodAnalysis'
import { useFoodEstimate } from '@/composables/useFoodEstimate'
import { useGuestQuota } from '@/composables/useGuestQuota'
import { useMealLog } from '@/composables/useMealLog'
import { useAuthStore } from '@/stores/auth'
import type { FoodAnalysisResult } from '@/types/food'

const route = useRoute()
const store = useAuthStore()
const { canUse, increment } = useGuestQuota()
const gateOpen = ref(false)

const isManual = computed(() => !!route.query.food)

const { result, streamingText, streamDone, loading, error, analyze, refetchAdvice } = useFoodAnalysis()
const { estimating, estimate } = useFoodEstimate()
const { todayStats, fetchTodayStats, logMeal } = useMealLog()

const todayConsumed = computed(() => todayStats.value?.total_calories ?? 0)
const todayGoal     = computed(() => store.user?.calorie_goal ?? 2000)

// Editable fields — mở popup (FoodEditSheet) để sửa thay vì ô nhập nhỏ chèn trực tiếp vào
// thẻ kết quả (trước đây khó bấm trúng trên di động, đặc biệt phần calo/macro không sửa được).
const editName     = ref('')
const editServing  = ref('')
const editCalories = ref(0)
const editProtein  = ref(0)
const editCarbs    = ref(0)
const editFat      = ref(0)
const editSodium   = ref(0)
const editSheetOpen = ref(false)

/**
 * Bản dữ liệu mà lời khuyên ĐANG hiển thị được sinh ra từ đó.
 *
 * Trước đây so sánh trực tiếp với `result` (bản AI đoán lần đầu) nên sửa đi rồi sửa quay lại
 * giá trị gốc sẽ không refetch, trong khi lời khuyên trên màn vẫn là của lần sửa giữa chừng.
 * Giữ snapshot riêng thì mọi thay đổi so với thứ user đang ĐỌC đều kích hoạt cập nhật.
 */
const advised = ref<FoodEditValues | null>(null)

function currentValues(): FoodEditValues {
  return {
    food_name: editName.value,
    serving:   editServing.value,
    calories:  editCalories.value,
    protein:   editProtein.value,
    carbs:     editCarbs.value,
    fat:       editFat.value,
    sodium:    editSodium.value,
  }
}

function applyValues(v: FoodEditValues) {
  editName.value     = v.food_name
  editServing.value  = v.serving
  editCalories.value = v.calories
  editProtein.value  = v.protein
  editCarbs.value    = v.carbs
  editFat.value      = v.fat
  editSodium.value   = v.sodium
}

// flush sync: template đọc thẳng editCalories (không còn fallback `|| result.calories`, vốn
// hiển thị sai khi user cố ý đặt 0 kcal) nên state phải sẵn sàng ngay khi result về.
watch(result, (r) => {
  if (!r) return
  const v: FoodEditValues = {
    food_name: r.food_name,
    serving:   r.serving,
    calories:  r.calories,
    protein:   r.protein,
    carbs:     r.carbs,
    fat:       r.fat,
    sodium:    r.sodium,
  }
  applyValues(v)
  advised.value = { ...v }
}, { immediate: true, flush: 'sync' })

const displayCalories = computed(() => result.value ? editCalories.value : 0)
const afterEating     = computed(() => todayConsumed.value + displayCalories.value)

const macros = computed(() => result.value ? [
  { label: 'Protein',  value: editProtein.value, unit: 'g',  color: 'var(--color-calor-green)' },
  { label: 'Carbs',    value: editCarbs.value,   unit: 'g',  color: '#FF9500' },
  { label: 'Chất béo', value: editFat.value,     unit: 'g',  color: '#FF2D55' },
  { label: 'Natri',    value: editSodium.value,  unit: 'mg', color: '#8a9a7d' },
] : [])

const lowConfidence = computed(() => result.value && result.value.confidence < 0.5)

// ── Typewriter effect ─────────────────────────────────────────────
const displayedText = ref('')
let   pendingChars  = ''
let   rafId: number | null = null

function drainBuffer() {
  if (!pendingChars) { rafId = null; return }
  // drain faster when stream is done
  const step = streamDone.value ? 6 : 3
  displayedText.value += pendingChars.slice(0, step)
  pendingChars         = pendingChars.slice(step)
  rafId = requestAnimationFrame(drainBuffer)
}

watch(streamingText, (newText, oldText) => {
  pendingChars += newText.slice((oldText ?? '').length)
  if (!rafId) rafId = requestAnimationFrame(drainBuffer)
})

watch(streamDone, (done) => {
  if (done && pendingChars && !rafId) rafId = requestAnimationFrame(drainBuffer)
})

// ── Confirm guard ─────────────────────────────────────────────────
const confirmed  = ref(false)
const savedImage = ref<string | null>(null)

function buildContext() {
  return { today_calories: todayConsumed.value, goal: todayGoal.value }
}

const refetchingAdvice = ref(false)
const busy = computed(() => estimating.value || refetchingAdvice.value)

// Lần lưu mới nhất thắng: nếu user kịp lưu lần nữa khi lần trước còn đang chạy, lần cũ phải
// bỏ kết quả của mình thay vì ghi đè `advised` bằng dữ liệu đã lỗi thời.
let saveSeq = 0

function openEditSheet() {
  editSheetOpen.value = true
}

/**
 * Lưu từ popup sửa món → tự động đồng bộ lại MỌI thứ phụ thuộc vào món:
 *
 * 1. Đổi tên/khẩu phần mà KHÔNG tự chỉnh số  → gọi AI ước tính lại calo + macro. Sửa
 *    "Phở bò" thành "Bánh xèo tôm thịt" mà vẫn giữ 450 kcal của phở là số liệu sai, và số
 *    sai đó sẽ đi thẳng vào nhật ký khi bấm Xác nhận.
 * 2. Tự chỉnh số                              → tôn trọng số user gõ, không ghi đè.
 * 3. Bất kỳ thay đổi nào                      → sinh lại lời khuyên AI theo dữ liệu cuối cùng.
 *
 * Chỉ chạy khi bấm "Lưu thay đổi", không theo từng phím gõ, để khỏi spam API.
 */
async function handleEditSave(values: FoodEditValues) {
  applyValues(values)

  const base = advised.value
  if (!base) return

  const seq = ++saveSeq

  const identityChanged = values.food_name !== base.food_name
                       || values.serving   !== base.serving
  const numbersChanged  = values.calories !== base.calories
                       || values.protein  !== base.protein
                       || values.carbs    !== base.carbs
                       || values.fat      !== base.fat
                       || values.sodium   !== base.sodium

  if (!identityChanged && !numbersChanged) return

  let next: FoodEditValues = { ...values }

  if (identityChanged && !numbersChanged) {
    const est = await estimate(values.food_name, values.serving)
    if (seq !== saveSeq) return
    if (!est) {
      toast.error('Chưa tính lại được calo cho món này — bạn có thể nhập tay trong ô Sửa.')
    } else {
      next = {
        ...values,
        serving:  est.serving || values.serving,
        calories: est.calories,
        protein:  est.protein,
        carbs:    est.carbs,
        fat:      est.fat,
        sodium:   est.sodium,
      }
      applyValues(next)
      // Đồng bộ badge nguồn (Thư viện/AI) + cảnh báo macro theo tên mới,
      // nếu không giữ nguyên nhãn cũ sẽ gây hiểu nhầm về mức tin cậy.
      if (result.value) {
        result.value.source  = est.source
        result.value.warning = est.warning ?? null
      }
      if (est.calories !== base.calories) {
        toast.success(`Đã tính lại: ${est.calories.toLocaleString('vi')} kcal`)
      }
    }
  }

  refetchingAdvice.value = true
  displayedText.value    = ''
  pendingChars           = ''
  await refetchAdvice(next, buildContext())
  if (seq !== saveSeq) return
  refetchingAdvice.value = false
  advised.value          = { ...next }
}

// Nhận diện có kiểm soát quota cho khách. Chỉ trừ lượt khi nhận diện thành công.
async function runAnalyze(text?: string | null) {
  if (!canUse('scan')) {
    gateOpen.value = true
    return
  }
  await analyze({ image: savedImage.value, text: text ?? null, context: buildContext() })
  if (!error.value) increment('scan')
}

onMounted(async () => {
  const barcodeRaw = sessionStorage.getItem('barcode_result')
  if (barcodeRaw) {
    sessionStorage.removeItem('barcode_result')
    const br     = JSON.parse(barcodeRaw) as FoodAnalysisResult
    result.value = br            // watcher phía trên tự nạp edit* + snapshot `advised`
    streamDone.value = true
    if (store.token) await fetchTodayStats()
    return
  }

  savedImage.value = sessionStorage.getItem('scan_image')
  sessionStorage.removeItem('scan_image')
  const text = route.query.food as string | undefined

  if (store.token) await fetchTodayStats()

  await runAnalyze(text)
})

async function confirmMeal() {
  // Guard: prevent double-tap duplicate
  if (!result.value || confirmed.value) return
  confirmed.value = true
  const mealToLog: FoodAnalysisResult = { ...result.value, ...currentValues() }
  // Lưu kèm lời khuyên AI (bản đầy đủ đã stream) để xem lại phần phân tích trong Lịch sử
  const advice = (streamingText.value || displayedText.value || '').trim() || null
  const ok = await logMeal(mealToLog, savedImage.value, advice)
  if (!ok) {
    confirmed.value = false
    toast.error('Không lưu được bữa ăn, hãy thử lại')
    return
  }
  // Đã lưu xong → ở lại trang, hiện nút Chia sẻ / Về trang chủ
  toast.success('Đã lưu bữa ăn! 🎉')
}

// ── Chia sẻ bữa ăn ─────────────────────────────────────────────────
const toast     = useToast()
const shareOpen = ref(false)

const shareMeal = computed<ShareMealData | null>(() => result.value ? {
  food_name: editName.value,
  serving:   editServing.value,
  calories:  displayCalories.value,
  protein:   editProtein.value,
  carbs:     editCarbs.value,
  fat:       editFat.value,
  image:     savedImage.value,
  logged_at: new Date().toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric' }),
  goal_percent: todayGoal.value > 0 ? Math.round((afterEating.value / todayGoal.value) * 100) : null,
} : null)

async function retry() {
  applyValues({ food_name: '', serving: '', calories: 0, protein: 0, carbs: 0, fat: 0, sodium: 0 })
  advised.value       = null
  editSheetOpen.value = false
  displayedText.value = ''
  pendingChars        = ''
  const text = route.query.food as string | undefined
  await runAnalyze(text)
}

onUnmounted(() => { if (rafId) cancelAnimationFrame(rafId) })
</script>

<template>
  <div class="flex flex-col bg-[#F2F2F7] min-h-full">

    <!-- Nav bar — sticky top -->
    <div class="sticky top-0 z-10 flex items-center px-5 py-3 bg-[#F2F2F7]/95 backdrop-blur-sm border-b-hairline border-ios-gray5">
      <button class="w-9 h-9 rounded-full bg-ios-gray5 flex items-center justify-center ios-press" @click="navigateTo('/scan')">
        <svg viewBox="0 0 24 24" class="w-5 h-5" style="fill:var(--color-calor-green)">
          <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
        </svg>
      </button>
      <h1 class="flex-1 text-[17px] font-semibold text-black text-center">Kết quả phân tích</h1>
      <div class="w-9"/>
    </div>

    <div class="flex-1 pb-2">
      <!-- ── Error state ── -->
      <div v-if="error && !loading" class="mx-5 mt-4 bg-ios-red/8 border border-ios-red/25 rounded-[18px] px-5 py-5 animate-fadeInUp" style="opacity:0">
        <div class="flex flex-col items-center gap-3 text-center">
          <CaloeyeCharacter mood="warning" :size="72" />
          <p class="text-[15px] font-semibold text-ios-red">Không thể phân tích</p>
          <p class="text-[13px] text-ios-gray leading-relaxed">{{ error }}</p>
          <button
            class="mt-1 bg-ios-red text-white px-6 py-2.5 rounded-[12px] text-[14px] font-semibold ios-press"
            @click="retry"
          >Thử lại</button>
        </div>
      </div>

      <!-- Food image / manual icon -->
      <div v-if="!error || loading" class="mx-5 mb-4">
        <div
          class="w-full h-44 rounded-[20px] overflow-hidden animate-scaleIn"
          :class="isManual ? 'bg-gradient-to-br from-ios-orange/20 to-ios-yellow/20 flex items-center justify-center' : 'bg-gray-200'"
        >
          <div v-if="isManual" class="flex flex-col items-center gap-2">
            <span class="text-5xl">📝</span>
            <p class="text-[14px] text-ios-gray font-medium">Nhập từ văn bản</p>
          </div>
          <img v-else-if="savedImage" :src="savedImage" class="w-full h-full object-cover" alt="Ảnh món ăn" />
          <div v-else class="w-full h-full bg-gradient-to-br from-amber-100 to-orange-100 flex items-center justify-center">
            <span class="text-7xl">🍜</span>
          </div>
        </div>
      </div>

      <!-- ── Loading: nhân vật chờ ── -->
      <div v-if="loading && !result" class="mx-5 mb-4 animate-fadeInUp" style="opacity:0">
        <!-- Character card -->
        <div class="bg-white rounded-[18px] px-5 py-5 mb-3 shadow-sm">
          <div class="flex items-center gap-4">
            <CaloeyeCharacter
              mood="waiting"
              :size="68"
              message="Bạn chờ chút nhé..."
              bubble-dir="right"
            />
            <div class="flex-1">
              <p class="text-[15px] font-semibold text-black mb-1">Đang phân tích món ăn</p>
              <p class="text-[13px] text-ios-gray leading-snug">AI đang nhận diện và tính toán dinh dưỡng cho bạn</p>
              <div class="flex gap-1 mt-2.5">
                <div v-for="i in 3" :key="i" class="w-2 h-2 rounded-full bg-calor-green" :class="`typing-dot-${i}`"/>
              </div>
            </div>
          </div>
        </div>
        <!-- Skeleton bars -->
        <div class="bg-white rounded-[18px] px-5 py-4 mb-3 shadow-sm">
          <div class="flex items-start justify-between">
            <div class="flex-1 space-y-2">
              <div class="h-2.5 w-14 bg-ios-gray5 rounded-full animate-pulse"/>
              <div class="h-5 w-36 bg-ios-gray5 rounded-full animate-pulse"/>
              <div class="h-2.5 w-24 bg-ios-gray5 rounded-full animate-pulse"/>
            </div>
            <div class="text-right space-y-1.5">
              <div class="h-8 w-14 bg-ios-gray5 rounded-full animate-pulse"/>
              <div class="h-2.5 w-8 bg-ios-gray5 rounded-full animate-pulse ml-auto"/>
            </div>
          </div>
          <div class="grid grid-cols-4 gap-2 mt-4 pt-4 border-t-hairline border-ios-gray5">
            <div v-for="i in 4" :key="i" class="flex flex-col items-center gap-1">
              <div class="w-8 h-8 rounded-full bg-ios-gray5 animate-pulse"/>
              <div class="h-2.5 w-8 bg-ios-gray5 rounded-full animate-pulse"/>
              <div class="h-2 w-10 bg-ios-gray5 rounded-full animate-pulse"/>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Confidence warning ── -->
      <div
        v-if="result && lowConfidence"
        class="mx-5 mb-3 bg-ios-orange/10 border border-ios-orange/30 rounded-[14px] px-4 py-3 flex items-center gap-3 animate-fadeInUp"
        style="opacity:0"
      >
        <span class="text-xl flex-shrink-0">⚠️</span>
        <p class="text-[13px] text-ios-orange leading-snug">
          <span class="font-semibold">AI không chắc chắn</span> về món ăn này.
          Hãy kiểm tra lại tên và calo trước khi lưu.
        </p>
      </div>

      <!-- ── Macro / kcal sanity warning (Atwater) ── -->
      <div
        v-if="result?.warning"
        class="mx-5 mb-3 bg-ios-orange/10 border border-ios-orange/30 rounded-[14px] px-4 py-3 flex items-center gap-3 animate-fadeInUp"
        style="opacity:0"
      >
        <span class="text-xl flex-shrink-0">⚖️</span>
        <p class="text-[13px] text-ios-orange leading-snug">{{ result.warning }}</p>
      </div>

      <!-- ── Food name + calories (hiện khi result về) — bấm để mở popup sửa ── -->
      <button
        v-if="result"
        type="button"
        class="block w-full text-left mx-5 bg-white rounded-[18px] px-5 py-4 mb-4 shadow-sm animate-fadeInUp ios-press disabled:opacity-60"
        style="width: calc(100% - 40px); opacity:0"
        :disabled="busy"
        @click="openEditSheet"
      >
        <div class="flex items-center justify-between mb-3">
          <p class="text-[13px] text-ios-gray uppercase tracking-wide font-semibold">Món ăn</p>
          <span class="flex items-center gap-1 text-[12px] font-medium text-ios-blue">
            <svg viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="currentColor">
              <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>
            </svg>
            {{ estimating ? 'Đang tính lại...' : refetchingAdvice ? 'Đang cập nhật...' : 'Sửa' }}
          </span>
        </div>

        <div class="flex items-start justify-between gap-3">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-1.5 flex-wrap">
              <h2 class="text-[22px] font-bold text-black">{{ editName }}</h2>
              <span
                v-if="result?.source === 'catalog'"
                class="flex-shrink-0 text-[10px] text-calor-green bg-calor-green/10 px-1.5 py-0.5 rounded-full font-medium"
              >📚 Thư viện</span>
              <span
                v-else-if="result?.source === 'ai'"
                class="flex-shrink-0 text-[10px] text-ios-gray bg-ios-gray/10 px-1.5 py-0.5 rounded-full font-medium"
              >🤖 AI ước tính</span>
            </div>
            <p class="text-[13px] text-ios-gray mt-1">{{ editServing }}</p>
          </div>
          <div class="text-right flex-shrink-0">
            <p class="text-[36px] font-bold text-ios-blue leading-none">{{ displayCalories }}</p>
            <p class="text-[13px] text-ios-gray">kcal</p>
          </div>
        </div>

        <!-- Macros grid -->
        <div class="grid grid-cols-4 gap-2 mt-4 pt-4 border-t-hairline border-ios-gray5">
          <div v-for="m in macros" :key="m.label" class="flex flex-col items-center">
            <div class="w-8 h-8 rounded-full flex items-center justify-center mb-1" :style="`background: ${m.color}18`">
              <div class="w-2.5 h-2.5 rounded-full" :style="`background: ${m.color}`"/>
            </div>
            <p class="text-[13px] font-semibold text-black">{{ m.value }}<span class="text-[10px] text-ios-gray">{{ m.unit }}</span></p>
            <p class="text-[10px] text-ios-gray text-center leading-tight">{{ m.label }}</p>
          </div>
        </div>
      </button>

      <!-- ── Impact analysis ── -->
      <div
        v-if="result"
        class="mx-5 bg-white rounded-[18px] px-5 py-4 mb-4 shadow-sm animate-fadeInUp delay-1"
        style="opacity:0"
      >
        <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-3">Tác động hôm nay</p>

        <div class="mb-2">
          <div class="flex justify-between text-[12px] text-ios-gray mb-1.5">
            <span>Hiện tại: {{ todayConsumed }} kcal</span>
            <span>Mục tiêu: {{ todayGoal }} kcal</span>
          </div>
          <div class="h-3 bg-ios-gray6 rounded-full overflow-hidden relative">
            <div
              class="absolute top-0 left-0 h-full rounded-full bg-ios-green transition-all duration-500"
              :style="`width: ${Math.min((todayConsumed / todayGoal) * 100, 100)}%`"
            />
            <div
              class="absolute top-0 left-0 h-full rounded-l-full opacity-40 transition-all duration-700"
              :style="`width: ${Math.min((afterEating / todayGoal) * 100, 100)}%; background: ${afterEating > todayGoal ? '#c96a6a' : 'var(--color-calor-green)'}`"
            />
          </div>
        </div>

        <div
          class="mt-3 rounded-[12px] px-3 py-2.5 flex items-center gap-2"
          :class="afterEating > todayGoal ? 'bg-ios-red/8' : 'bg-ios-green/8'"
        >
          <span class="text-lg">{{ afterEating > todayGoal ? '⚠️' : '✅' }}</span>
          <p class="text-[13px]" :class="afterEating > todayGoal ? 'text-ios-red' : 'text-ios-green'">
            <span class="font-semibold">Sau khi ăn: {{ afterEating.toLocaleString('vi') }} kcal</span>
            — {{ afterEating > todayGoal
              ? `Vượt ${(afterEating - todayGoal).toLocaleString('vi')} kcal so với mục tiêu!`
              : `Còn ${(todayGoal - afterEating).toLocaleString('vi')} kcal cho bữa tối.` }}
          </p>
        </div>
      </div>

      <!-- Character reaction -->
      <div
        v-if="result && streamDone"
        class="mx-5 mb-4 rounded-[18px] px-4 py-4 flex items-center gap-4 animate-fadeInUp"
        :class="afterEating > todayGoal ? 'bg-ios-orange/10 border border-ios-orange/25' : 'bg-calor-light border border-calor-mint/50'"
        style="opacity:0"
      >
        <CaloeyeCharacter
          :mood="afterEating > todayGoal ? 'warning' : 'celebrate'"
          :size="64"
          :message="afterEating > todayGoal ? 'Cẩn thận nhé! 🔥' : 'Tuyệt vời! 🎉'"
          bubble-dir="right"
        />
        <p class="flex-1 text-[14px] leading-relaxed" :class="afterEating > todayGoal ? 'text-ios-orange' : 'text-calor-deep'">
          <template v-if="afterEating > todayGoal">
            Món này khá nhiều calo. Hãy cân nhắc vận động thêm để bù lại nhé!
          </template>
          <template v-else>
            Lựa chọn tuyệt vời! Bữa ăn này rất cân bằng và phù hợp với mục tiêu của bạn.
          </template>
        </p>
      </div>

      <!-- AI streaming advice -->
      <div
        v-if="result || streamingText"
        class="mx-5 bg-white rounded-[18px] px-5 py-4 mb-4 shadow-sm animate-fadeInUp delay-2"
        style="opacity:0"
      >
        <div class="flex items-center gap-2 mb-3">
          <div class="w-6 h-6 rounded-full bg-calor-green flex items-center justify-center">
            <svg viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="white">
              <path d="M12 2a5 5 0 110 10A5 5 0 0112 2zm0 12c5.33 0 8 2.67 8 4v2H4v-2c0-1.33 2.67-4 8-4z"/>
            </svg>
          </div>
          <p class="text-[13px] font-semibold text-black">Lời khuyên từ AI</p>
          <!-- Streaming dots -->
          <div v-if="busy || !streamDone || displayedText.length < streamingText.length" class="ml-auto flex items-center gap-1">
            <span v-if="estimating" class="text-[11px] text-ios-gray mr-1">Đang tính lại dinh dưỡng…</span>
            <div v-for="i in 3" :key="i" class="w-1 h-1 rounded-full bg-ios-blue" :class="`typing-dot-${i}`"/>
          </div>
          <span v-else class="ml-auto text-[11px] text-ios-green font-medium">✓ Hoàn tất</span>
        </div>
        <div class="text-[14px] text-black/80 leading-relaxed whitespace-pre-wrap">{{ displayedText }}<span
          v-if="!streamDone || displayedText.length < streamingText.length"
          class="inline-block w-[2px] h-[1em] bg-calor-green animate-pulse ml-[1px] align-middle"
        /></div>
        <!-- Nguồn tham chiếu — user click để xem chi tiết citations -->
        <div v-if="streamDone && displayedText.length >= streamingText.length" class="mt-3 pt-3 border-t-hairline border-ios-gray5">
          <AdvisorySource compact :only="['vdd-mon-an']" />
        </div>
      </div>

      <div class="h-2"/>
    </div>

    <!-- Action buttons — sticky bottom (sits above tab bar) -->
    <div class="sticky bottom-0 z-10 px-5 py-4 bg-[#F2F2F7]/95 backdrop-blur-sm border-t-hairline border-ios-gray5">
      <!-- Đã lưu xong → mời chia sẻ -->
      <div v-if="confirmed" class="flex gap-3 animate-fadeInUp">
        <button
          class="flex-1 h-[52px] rounded-[14px] bg-ios-gray5 text-black font-semibold text-[15px] ios-press"
          @click="navigateTo('/home')"
        >Về trang chủ</button>
        <button
          class="flex-[2] h-[52px] rounded-[14px] bg-calor-green text-white font-semibold text-[17px] ios-press flex items-center justify-center gap-2"
          @click="shareOpen = true"
        >
          <svg viewBox="0 0 24 24" class="w-5 h-5" fill="white">
            <path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81a3 3 0 10-3-3c0 .24.04.47.09.7L8.04 9.81A2.99 2.99 0 003 12a3 3 0 005.04 2.19l7.12 4.16c-.05.21-.08.43-.08.65a2.92 2.92 0 102.92-2.92z"/>
          </svg>
          <span>Chia sẻ bữa ăn</span>
        </button>
      </div>

      <div v-else class="flex gap-3">
        <button
          class="flex-1 h-[52px] rounded-[14px] bg-ios-gray5 text-black font-semibold text-[15px] ios-press"
          @click="navigateTo('/scan')"
        >Hủy</button>
        <button
          class="flex-[2] h-[52px] rounded-[14px] bg-calor-green text-white font-semibold text-[17px] ios-press flex items-center justify-center gap-2 disabled:opacity-40"
          :disabled="!result || !!error || confirmed"
          @click="confirmMeal"
        >
          <svg viewBox="0 0 24 24" class="w-5 h-5" fill="white">
            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
          </svg>
          <span>Xác nhận & Lưu</span>
        </button>
      </div>
    </div>
  </div>

  <GuestGateModal
    v-model:open="gateOpen"
    feature="nhận diện món ăn"
    @dismiss="navigateTo('/home')"
  />

  <ShareMealSheet v-model:open="shareOpen" :meal="shareMeal" />

  <FoodEditSheet
    v-model:open="editSheetOpen"
    :initial="{
      food_name: editName,
      serving:   editServing,
      calories:  editCalories,
      protein:   editProtein,
      carbs:     editCarbs,
      fat:       editFat,
      sodium:    editSodium,
    }"
    @save="handleEditSave"
  />
</template>
