<script setup lang="ts">
import CaloeyeCharacter from '@/components/caloeye/Character.vue'
import { useFoodAnalysis } from '@/composables/useFoodAnalysis'
import { useMealLog } from '@/composables/useMealLog'
import { useAuthStore } from '@/stores/auth'
import type { FoodAnalysisResult } from '@/types/food'

const route = useRoute()
const store = useAuthStore()

const isManual = computed(() => !!route.query.food)

const { result, streamingText, streamDone, loading, error, analyze } = useFoodAnalysis()
const { todayStats, fetchTodayStats, logMeal } = useMealLog()

const todayConsumed = computed(() => todayStats.value?.total_calories ?? 0)
const todayGoal     = computed(() => store.user?.calorie_goal ?? 2000)

// Editable fields
const editName     = ref('')
const editCalories = ref(0)
const isEditing    = ref(false)

watch(result, (r) => {
  if (r && !editName.value) {
    editName.value     = r.food_name
    editCalories.value = r.calories
  }
}, { immediate: true })

const displayCalories = computed(() => editCalories.value || (result.value?.calories ?? 0))
const afterEating     = computed(() => todayConsumed.value + displayCalories.value)

const macros = computed(() => result.value ? [
  { label: 'Protein',  value: result.value.protein, unit: 'g',  color: '#007AFF' },
  { label: 'Carbs',    value: result.value.carbs,   unit: 'g',  color: '#FF9500' },
  { label: 'Chất béo', value: result.value.fat,     unit: 'g',  color: '#FF2D55' },
  { label: 'Natri',    value: result.value.sodium,  unit: 'mg', color: '#8E8E93' },
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

onMounted(async () => {
  const barcodeRaw = sessionStorage.getItem('barcode_result')
  if (barcodeRaw) {
    sessionStorage.removeItem('barcode_result')
    const br        = JSON.parse(barcodeRaw) as FoodAnalysisResult
    result.value    = br
    editName.value  = br.food_name
    editCalories.value = br.calories
    streamDone.value = true
    if (store.token) await fetchTodayStats()
    return
  }

  savedImage.value = sessionStorage.getItem('scan_image')
  sessionStorage.removeItem('scan_image')
  const text = route.query.food as string | undefined

  if (store.token) await fetchTodayStats()

  await analyze({
    image:   savedImage.value,
    text:    text ?? null,
    context: buildContext(),
  })
})

async function confirmMeal() {
  // Guard: prevent double-tap duplicate
  if (!result.value || confirmed.value) return
  confirmed.value = true
  const mealToLog: FoodAnalysisResult = {
    ...result.value,
    food_name: editName.value || result.value.food_name,
    calories:  editCalories.value || result.value.calories,
  }
  await logMeal(mealToLog)
  await new Promise(r => setTimeout(r, 500))
  navigateTo('/home')
}

async function retry() {
  editName.value     = ''
  editCalories.value = 0
  isEditing.value    = false
  displayedText.value = ''
  pendingChars        = ''
  const text = route.query.food as string | undefined
  await analyze({
    image:   savedImage.value,
    text:    text ?? null,
    context: buildContext(),
  })
}

onUnmounted(() => { if (rafId) cancelAnimationFrame(rafId) })
</script>

<template>
  <div class="flex flex-col bg-[#F2F2F7] min-h-full">

    <!-- Nav bar — sticky top -->
    <div class="sticky top-0 z-10 flex items-center px-5 py-3 bg-[#F2F2F7]/95 backdrop-blur-sm border-b-hairline border-ios-gray5">
      <button class="w-9 h-9 rounded-full bg-ios-gray5 flex items-center justify-center ios-press" @click="navigateTo('/scan')">
        <svg viewBox="0 0 24 24" class="w-5 h-5" fill="#18A874">
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

      <!-- ── Food name + calories (hiện khi result về) ── -->
      <div
        v-if="result"
        class="mx-5 bg-white rounded-[18px] px-5 py-4 mb-4 shadow-sm animate-fadeInUp"
        style="opacity:0"
      >
        <!-- Edit mode toggle -->
        <div class="flex items-center justify-between mb-3">
          <p class="text-[13px] text-ios-gray uppercase tracking-wide font-semibold">Món ăn</p>
          <button
            class="flex items-center gap-1 text-[12px] font-medium ios-press"
            :class="isEditing ? 'text-ios-green' : 'text-ios-blue'"
            @click="isEditing = !isEditing"
          >
            <svg viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="currentColor">
              <path v-if="isEditing" d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
              <path v-else d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>
            </svg>
            {{ isEditing ? 'Xong' : 'Chỉnh sửa' }}
          </button>
        </div>

        <div class="flex items-start justify-between gap-3">
          <div class="flex-1 min-w-0">
            <input
              v-if="isEditing"
              v-model="editName"
              class="w-full text-[20px] font-bold text-black bg-ios-gray6 rounded-[10px] px-3 py-1.5 outline-none"
            />
            <h2 v-else class="text-[22px] font-bold text-black">{{ editName || result.food_name }}</h2>
            <p class="text-[13px] text-ios-gray mt-1">{{ result.serving }}</p>
          </div>
          <div class="text-right flex-shrink-0">
            <div v-if="isEditing" class="flex items-center gap-1 justify-end">
              <input
                v-model.number="editCalories"
                type="number"
                class="w-20 text-[28px] font-bold text-ios-blue bg-ios-gray6 rounded-[10px] px-2 py-1 outline-none text-right"
              />
            </div>
            <p v-else class="text-[36px] font-bold text-ios-blue leading-none">{{ editCalories || result.calories }}</p>
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
      </div>

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
              :style="`width: ${Math.min((afterEating / todayGoal) * 100, 100)}%; background: ${afterEating > todayGoal ? '#FF3B30' : '#007AFF'}`"
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
          <div v-if="!streamDone || displayedText.length < streamingText.length" class="ml-auto flex gap-1">
            <div v-for="i in 3" :key="i" class="w-1 h-1 rounded-full bg-ios-blue" :class="`typing-dot-${i}`"/>
          </div>
          <span v-else class="ml-auto text-[11px] text-ios-green font-medium">✓ Hoàn tất</span>
        </div>
        <div class="text-[14px] text-black/80 leading-relaxed whitespace-pre-wrap">{{ displayedText }}<span
          v-if="!streamDone || displayedText.length < streamingText.length"
          class="inline-block w-[2px] h-[1em] bg-calor-green animate-pulse ml-[1px] align-middle"
        /></div>
      </div>

      <div class="h-2"/>
    </div>

    <!-- Action buttons — sticky bottom (sits above tab bar) -->
    <div class="sticky bottom-0 z-10 px-5 py-4 bg-[#F2F2F7]/95 backdrop-blur-sm border-t-hairline border-ios-gray5">
      <div class="flex gap-3">
        <button
          class="flex-1 h-[52px] rounded-[14px] bg-ios-gray5 text-black font-semibold text-[15px] ios-press"
          @click="navigateTo('/scan')"
        >Hủy</button>
        <button
          class="flex-[2] h-[52px] rounded-[14px] text-white font-semibold text-[17px] ios-press flex items-center justify-center gap-2 disabled:opacity-40"
          :class="confirmed ? 'bg-ios-green' : 'bg-calor-green'"
          :disabled="!result || !!error || confirmed"
          @click="confirmMeal"
        >
          <svg v-if="confirmed" viewBox="0 0 24 24" class="w-5 h-5 animate-scaleIn" fill="white">
            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
          </svg>
          <svg v-else viewBox="0 0 24 24" class="w-5 h-5" fill="white">
            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
          </svg>
          <span>{{ confirmed ? 'Đã lưu!' : 'Xác nhận & Lưu' }}</span>
        </button>
      </div>
    </div>
  </div>
</template>
