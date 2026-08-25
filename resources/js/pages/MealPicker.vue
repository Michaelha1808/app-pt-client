<script setup lang="ts">
import AdvisorySource from '@/components/common/AdvisorySource.vue'
import CaloeyeCharacter from '@/components/caloeye/Character.vue'
import DishPickRow from '@/components/food/DishPickRow.vue'
import GuestGateModal from '@/components/common/GuestGateModal.vue'
import { useFoodDetect } from '@/composables/useFoodDetect'
import { useFoodEstimate } from '@/composables/useFoodEstimate'
import { useGuestQuota } from '@/composables/useGuestQuota'
import { useMealLog } from '@/composables/useMealLog'
import { useMealAdvice } from '@/composables/useMealAdvice'
import { useAuthStore } from '@/stores/auth'
import { apiFetch } from '@/utils/api'
import { dishCalories, dishMacro, totalCalories, selectedCount, formatQty } from '@/utils/nutrition'
import type { FoodEditValues } from '@/components/food/FoodEditSheet.vue'
import type { DishPick } from '@/types/food'
import type { FoodAnalysisResult } from '@/types/food'

const route = useRoute()
const store = useAuthStore()
const { dishes, detectionId, loading, error, detect } = useFoodDetect()
const { canUse, increment } = useGuestQuota()
const { todayStats, fetchTodayStats, logMeals } = useMealLog()
const { advice, streaming: adviceStreaming, fetchAdvice, resetAdvice } = useMealAdvice()
const { estimate } = useFoodEstimate()

const picks        = ref<DishPick[]>([])
const gateOpen     = ref(false)
const saving       = ref(false)
const feedbackSent = ref(false)
/** Index các món đang được AI ước tính lại dinh dưỡng (sau khi user sửa tên) */
const estimatingAt = ref<Set<number>>(new Set())

const total      = computed(() => totalCalories(picks.value))
const pickCount  = computed(() => selectedCount(picks.value))
const savedImage = ref<string | null>(null)

// ── Tác động calo hôm nay (cho nhân vật phản ứng) ──
const todayConsumed = computed(() => todayStats.value?.total_calories ?? 0)
const todayGoal     = computed(() => store.user?.calorie_goal ?? 2000)
const afterEating   = computed(() => todayConsumed.value + total.value)
const overGoal      = computed(() => afterEating.value > todayGoal.value)

async function runDetect() {
  if (!canUse('scan')) {
    gateOpen.value = true
    return
  }
  const text = route.query.food as string | undefined
  await detect({ image: savedImage.value, text: text ?? null })
  if (!error.value) {
    increment('scan')
    picks.value = dishes.value.map(d => ({ ...d, selected: true, quantity: d.quantity_default }))
    // Nhận xét AI do watcher `picks` bên dưới kích hoạt — cả lần đầu lẫn mọi lần user sửa
  }
}

onMounted(async () => {
  savedImage.value = sessionStorage.getItem('scan_image')
  sessionStorage.removeItem('scan_image')
  if (store.token) await fetchTodayStats()
  await runDetect()
})

// Gửi dataset feedback (AI đoán vs user chốt) để cải thiện model — best-effort, không chặn UI.
async function sendDetectFeedback(saved: boolean) {
  if (feedbackSent.value || !detectionId.value || picks.value.length === 0) return
  feedbackSent.value = true
  try {
    await apiFetch(`/food/detect/${detectionId.value}/feedback`, {
      method: 'POST',
      body: {
        saved,
        dishes: picks.value.map(d => ({
          food_name: d.food_name,
          calories:  d.calories,   // calo/1 đơn vị (đã sửa nếu có)
          quantity:  d.quantity,
          selected:  d.selected,
        })),
      },
    })
  } catch {
    // im lặng: thu dataset không được làm hỏng trải nghiệm
  }
}

// Nếu rời màn mà chưa lưu (chụp lại / thoát) vẫn ghi lại tín hiệu (saved=false).
onBeforeUnmount(() => { sendDetectFeedback(false) })

function askMealAdvice() {
  const selected = picks.value.filter(d => d.selected)
  if (selected.length === 0) {
    resetAdvice()          // bỏ hết món → nhận xét cũ không còn đúng với bữa nào cả
    return
  }
  fetchAdvice({
    dishes: selected.map(d => ({
      name: d.quantity !== 1 ? `${d.food_name} ×${formatQty(d.quantity)}` : d.food_name,
      calories: dishCalories(d, d.quantity),
    })),
    total_calories: total.value,
    context: { goal: todayGoal.value, today_calories: todayConsumed.value },
  })
}

/**
 * Bất kỳ thay đổi nào lên danh sách món (sửa trong popup, đổi số lượng, chọn/bỏ chọn) đều
 * làm nhận xét AI hiện tại sai đi — trước đây user phải tự bấm "Phân tích lại", còn tổng kcal
 * thì đã đúng ngay nên nhận xét lệch với con số ngay bên cạnh.
 *
 * Debounce vì stepper +/- bấm liên tục sẽ bắn rất nhiều lần; useMealAdvice cũng huỷ luồng cũ
 * nên nhận xét hiển thị luôn là của trạng thái mới nhất.
 */
let adviceTimer: ReturnType<typeof setTimeout> | null = null

function scheduleMealAdvice() {
  if (adviceTimer) clearTimeout(adviceTimer)
  adviceTimer = setTimeout(() => { adviceTimer = null; askMealAdvice() }, 700)
}

watch(picks, () => {
  // Còn món đang chờ AI ước tính lại calo → nhận xét bây giờ sẽ dựa trên số sắp bị thay.
  // handleDishSave() tự lên lịch lại khi ước tính xong.
  if (picks.value.length > 0 && estimatingAt.value.size === 0) scheduleMealAdvice()
}, { deep: true })

onBeforeUnmount(() => { if (adviceTimer) clearTimeout(adviceTimer) })

/**
 * User lưu popup sửa món → cùng quy tắc với Result.vue: đổi tên/khẩu phần mà không tự chỉnh
 * số thì AI ước tính lại calo + macro CHO 1 ĐƠN VỊ (nhân với stepper số lượng sau), còn nếu
 * user tự gõ số thì tôn trọng số đó. Nhận xét cả bữa do watcher `picks` tự sinh lại.
 */
async function handleDishSave(index: number, values: FoodEditValues) {
  const dish = picks.value[index]
  if (!dish) return

  const identityChanged = values.food_name !== dish.food_name || values.serving !== dish.serving
  const numbersChanged  = values.calories !== dish.calories
                       || values.protein  !== dish.protein
                       || values.carbs    !== dish.carbs
                       || values.fat      !== dish.fat
                       || values.sodium   !== dish.sodium

  Object.assign(dish, values)

  // User đã chốt lại số liệu → không còn là món khớp thư viện chuẩn nữa, bỏ nhãn 📚 Thư viện
  // (giữ nhãn sẽ khiến số user tự sửa trông như số đã được kiểm chứng).
  if (identityChanged || numbersChanged) {
    dish.source  = 'ai'
    dish.dish_id = null
  }

  if (!identityChanged || numbersChanged) return

  estimatingAt.value = new Set(estimatingAt.value).add(index)
  try {
    const est = await estimate(values.food_name, values.serving, dish.unit_label)
    const target = picks.value[index]
    // User có thể đã sửa tiếp trong lúc chờ → chỉ áp khi tên vẫn là tên đã gửi đi
    if (est && target && target.food_name === values.food_name) {
      target.serving  = est.serving || target.serving
      target.calories = est.calories
      target.protein  = est.protein
      target.carbs    = est.carbs
      target.fat      = est.fat
      target.sodium   = est.sodium
    }
  } finally {
    const next = new Set(estimatingAt.value)
    next.delete(index)
    estimatingAt.value = next
    scheduleMealAdvice()
  }
}

async function confirmMeal() {
  if (saving.value || pickCount.value === 0) return
  saving.value = true

  const results: FoodAnalysisResult[] = picks.value
    .filter(d => d.selected)
    .map((d) => {
      const qtyLabel = `${formatQty(d.quantity)} ${d.unit_label}`.trim()
      const name = d.quantity !== 1 ? `${d.food_name} ×${formatQty(d.quantity)}` : d.food_name
      return {
        food_name:    name,
        serving:      qtyLabel,
        calories:     dishCalories(d, d.quantity),
        protein:      dishMacro(d, 'protein', d.quantity),
        carbs:        dishMacro(d, 'carbs', d.quantity),
        fat:          dishMacro(d, 'fat', d.quantity),
        sodium:       dishMacro(d, 'sodium', d.quantity),
        confidence:   d.confidence,
        advice_short: '',
      }
    })

  // Lưu kèm phân tích AI của cả bữa (1 lần cho mọi món) để xem lại trong Lịch sử.
  await logMeals(results, savedImage.value, (advice.value || '').trim() || null)
  await sendDetectFeedback(true)
  await new Promise(r => setTimeout(r, 400))
  navigateTo('/home')
}
</script>

<template>
  <div class="flex flex-col bg-[var(--color-calor-bg)] min-h-full">
    <!-- Nav bar -->
    <div class="sticky top-0 z-10 flex items-center px-5 py-3 bg-[var(--color-calor-bg)]/95 backdrop-blur-sm border-b-hairline border-ios-gray5">
      <button class="w-9 h-9 rounded-full bg-ios-gray5 flex items-center justify-center ios-press" @click="navigateTo('/scan')">
        <svg viewBox="0 0 24 24" class="w-5 h-5" style="fill:var(--color-calor-green)">
          <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
        </svg>
      </button>
      <h1 class="flex-1 text-[17px] font-semibold text-black text-center">Chọn món</h1>
      <div class="w-9"/>
    </div>

    <div class="flex-1 pb-28">
      <!-- Loading -->
      <div v-if="loading" class="mx-5 mt-4">
        <!-- Nhân vật chờ (AVO-04-cho-chut) -->
        <div class="bg-white rounded-[18px] px-5 pt-8 pb-6 mb-3 shadow-sm flex flex-col items-center text-center">
          <CaloeyeCharacter mood="waiting" :size="84" message="Bạn chờ chút nhé..." bubble-dir="top" />
          <p class="text-[15px] font-semibold text-black mt-3">Đang phân tích món ăn</p>
          <p class="text-[13px] text-ios-gray leading-snug mt-1 max-w-[260px]">AI đang nhận diện các món trong ảnh của bạn</p>
        </div>
        <!-- Skeleton danh sách món -->
        <div class="bg-white rounded-[18px] divide-y divide-ios-gray6">
          <div v-for="i in 4" :key="i" class="flex items-center gap-3 px-4 py-4">
            <div class="w-6 h-6 rounded-full bg-ios-gray6 animate-pulse"/>
            <div class="flex-1">
              <div class="h-3.5 bg-ios-gray6 rounded w-1/2 animate-pulse"/>
              <div class="h-2.5 bg-ios-gray6 rounded w-1/3 mt-2 animate-pulse"/>
            </div>
            <div class="w-20 h-8 bg-ios-gray6 rounded-full animate-pulse"/>
          </div>
        </div>
      </div>

      <!-- Error -->
      <div v-else-if="error" class="mx-5 mt-4 bg-ios-red/8 border border-ios-red/25 rounded-[18px] px-5 py-5">
        <div class="flex flex-col items-center gap-3 text-center">
          <CaloeyeCharacter mood="warning" :size="72" />
          <p class="text-[14px] text-ios-red">{{ error }}</p>
          <button class="mt-1 px-5 h-10 rounded-full bg-ios-blue text-white text-[14px] font-semibold ios-press" @click="runDetect">
            Thử lại
          </button>
        </div>
      </div>

      <!-- Empty -->
      <div v-else-if="picks.length === 0" class="mx-5 mt-8 flex flex-col items-center gap-3 text-center">
        <CaloeyeCharacter mood="reminder" :size="88" />
        <p class="text-[15px] font-medium text-black">Không nhận diện được món nào</p>
        <p class="text-[13px] text-ios-gray">Hãy chụp rõ hơn các món trong bữa ăn nhé.</p>
        <button class="mt-2 px-5 h-11 rounded-full bg-ios-blue text-white text-[15px] font-semibold ios-press" @click="navigateTo('/scan')">
          Chụp lại
        </button>
      </div>

      <!-- Dish list -->
      <template v-else>
        <p class="px-5 pt-4 pb-2 text-[13px] text-ios-gray">
          Nhận diện được <span class="font-semibold text-black">{{ picks.length }}</span> món. Chọn món bạn đã ăn và chỉnh số lượng.
        </p>
        <div class="mx-5 bg-white rounded-[18px] overflow-hidden shadow-sm divide-y divide-ios-gray6">
          <DishPickRow
            v-for="(d, i) in picks"
            :key="i"
            :dish="d"
            :estimating="estimatingAt.has(i)"
            @update:selected="d.selected = $event"
            @update:quantity="d.quantity = $event"
            @save="handleDishSave(i, $event)"
          />
        </div>

        <!-- Nhân vật phản ứng theo kết quả -->
        <div
          v-if="pickCount > 0"
          class="mx-5 mt-4 rounded-[18px] px-4 py-4 flex items-center gap-4 animate-fadeInUp"
          :class="overGoal ? 'bg-ios-orange/10 border border-ios-orange/25' : 'bg-calor-light border border-calor-mint/50'"
        >
          <CaloeyeCharacter
            :mood="overGoal ? 'warning' : 'celebrate'"
            :size="64"
            :message="overGoal ? 'Cẩn thận nhé! 🔥' : 'Tuyệt vời! 🎉'"
            bubble-dir="right"
          />
          <p class="flex-1 text-[14px] leading-relaxed" :class="overGoal ? 'text-ios-orange' : 'text-calor-deep'">
            <template v-if="overGoal">
              Bữa này khiến bạn vượt mục tiêu {{ (afterEating - todayGoal).toLocaleString('vi') }} kcal. Cân nhắc bớt món hoặc vận động thêm nhé!
            </template>
            <template v-else>
              Bữa ăn này phù hợp với mục tiêu. Sau khi ăn bạn còn {{ (todayGoal - afterEating).toLocaleString('vi') }} kcal cho hôm nay.
            </template>
          </p>
        </div>

        <!-- AI nhận xét cả bữa -->
        <div class="mx-5 mt-4 bg-gradient-to-r from-ios-blue/5 to-ios-purple/5 border border-ios-blue/15 rounded-[18px] p-4">
          <div class="flex items-start gap-3">
            <div class="w-8 h-8 rounded-full bg-ios-blue flex items-center justify-center flex-shrink-0 mt-0.5">
              <svg viewBox="0 0 24 24" class="w-4 h-4" fill="white"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-[13px] font-semibold text-black">Nhận xét bữa ăn từ AI</p>
              <p v-if="advice" class="text-[13px] text-ios-gray mt-1 leading-relaxed whitespace-pre-wrap">{{ advice }}</p>
              <p v-else-if="!adviceStreaming" class="text-[12px] text-ios-gray mt-0.5">
                {{ pickCount === 0 ? 'Chọn ít nhất 1 món để AI đánh giá bữa ăn.' : 'AI đang chuẩn bị nhận xét cho bữa này…' }}
              </p>
              <p class="mt-1.5 text-[11px] text-ios-gray3">Nhận xét tự cập nhật mỗi khi bạn sửa món hoặc đổi số lượng.</p>
              <button
                class="mt-1.5 text-[13px] text-ios-blue font-medium ios-press disabled:opacity-50"
                :disabled="adviceStreaming || pickCount === 0"
                @click="askMealAdvice"
              >
                {{ adviceStreaming ? 'Đang phân tích…' : 'Phân tích lại' }}
              </button>
              <div v-if="advice && !adviceStreaming" class="mt-2 pt-2 border-t-hairline border-ios-gray5">
                <AdvisorySource compact :only="['vdd-mon-an']" />
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- Sticky total + confirm -->
    <div
      v-if="!loading && !error && picks.length > 0"
      class="fixed bottom-0 left-0 right-0 z-20 bg-white/95 backdrop-blur-sm border-t-hairline border-ios-gray5 px-5 pt-3 pb-6"
    >
      <div class="flex items-center justify-between mb-3">
        <span class="text-[13px] text-ios-gray">{{ pickCount }} món đã chọn</span>
        <span class="text-[17px] font-bold text-black">{{ total.toLocaleString('vi') }} kcal</span>
      </div>
      <button
        class="w-full h-12 rounded-[14px] text-white text-[16px] font-semibold ios-press flex items-center justify-center gap-2 transition-colors"
        :class="pickCount > 0 ? 'bg-calor-green' : 'bg-ios-gray3'"
        :disabled="pickCount === 0 || saving"
        @click="confirmMeal"
      >
        <span>{{ saving ? 'Đang lưu...' : 'Xác nhận & Lưu' }}</span>
      </button>
    </div>
  </div>

  <GuestGateModal
    v-model:open="gateOpen"
    feature="nhận diện món ăn"
    @dismiss="navigateTo('/home')"
  />
</template>
