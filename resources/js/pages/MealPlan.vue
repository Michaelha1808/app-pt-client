<script setup lang="ts">
import CaloeyeCharacter from '@/components/caloeye/Character.vue'
import { useMealPlan } from '@/composables/useMealPlan'
import type { DailyPlan, MonthlyPlan, PlanScope, WeeklyPlan } from '@/types/plan'

const { plan, reasoning, isStale, isDraft, loading, generating, applying, error, fetchPlan, generate, apply } = useMealPlan()
const { success, error: toastError } = useToast()

const scope = ref<PlanScope>('daily')

async function applyPlan() {
  if (applying.value) return
  const ok = await apply(scope.value)
  if (ok) success(`Đã áp dụng kế hoạch ${SCOPE_LABEL[scope.value]} 🎯`)
  else toastError(error.value ?? 'Không thể áp dụng kế hoạch.')
}

const dailyPlan   = computed(() => (scope.value === 'daily' ? plan.value as DailyPlan | null : null))
const weeklyPlan  = computed(() => (scope.value === 'weekly' ? plan.value as WeeklyPlan | null : null))
const monthlyPlan = computed(() => (scope.value === 'monthly' ? plan.value as MonthlyPlan | null : null))

// Thứ hôm nay theo chuẩn ISO (1 = Thứ 2 … 7 = Chủ nhật) để làm nổi ngày hiện tại.
const todayIso = ((new Date().getDay() + 6) % 7) + 1

const SCOPE_LABEL: Record<PlanScope, string> = {
  daily: 'ngày mai', weekly: 'tuần này', monthly: 'tháng này',
}

const SLOT_LABEL: Record<string, string> = {
  breakfast: 'Bữa sáng', lunch: 'Bữa trưa', dinner: 'Bữa tối', snack: 'Bữa phụ',
}
const SLOT_ICON: Record<string, string> = {
  breakfast: '🌅', lunch: '🍚', dinner: '🌙', snack: '🍎',
}
const WORKOUT_ICON: Record<string, string> = {
  cardio: '🏃', strength: '💪', flexibility: '🧘',
}

async function switchScope(s: PlanScope) {
  if (scope.value === s) return
  scope.value = s
  await fetchPlan(s)
}

onMounted(() => fetchPlan('daily'))
</script>

<template>
  <div class="flex flex-col bg-[var(--color-calor-bg)] min-h-full pb-6">
    <!-- Header -->
    <div class="px-5 pt-3 pb-2">
      <h1 class="font-display text-[22px] font-bold text-black">Kế hoạch của bạn</h1>
      <p class="text-[13px] text-ios-gray mt-0.5">AI gợi ý dựa trên dữ liệu ăn uống của bạn</p>
    </div>

    <!-- Tabs -->
    <div class="px-5 pb-3">
      <div class="flex bg-ios-gray6 rounded-[12px] p-1">
        <button
          class="flex-1 h-9 rounded-[9px] text-[14px] font-medium transition-colors"
          :class="scope === 'daily' ? 'bg-white text-black shadow-sm' : 'text-ios-gray'"
          @click="switchScope('daily')"
        >Ngày mai</button>
        <button
          class="flex-1 h-9 rounded-[9px] text-[14px] font-medium transition-colors"
          :class="scope === 'weekly' ? 'bg-white text-black shadow-sm' : 'text-ios-gray'"
          @click="switchScope('weekly')"
        >Tuần này</button>
        <button
          class="flex-1 h-9 rounded-[9px] text-[14px] font-medium transition-colors"
          :class="scope === 'monthly' ? 'bg-white text-black shadow-sm' : 'text-ios-gray'"
          @click="switchScope('monthly')"
        >Tháng này</button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading && !plan" class="mx-5 mt-2 space-y-3">
      <div v-for="i in 3" :key="i" class="bg-white rounded-[18px] h-24 animate-pulse"/>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="mx-5 mt-4 bg-ios-red/8 border border-ios-red/25 rounded-[18px] px-5 py-5 text-center">
      <CaloeyeCharacter mood="warning" :size="64" />
      <p class="text-[14px] text-ios-red mt-2">{{ error }}</p>
      <button class="mt-3 px-5 h-10 rounded-full bg-ios-blue text-white text-[14px] font-semibold ios-press" @click="generate(scope)">
        Thử lại
      </button>
    </div>

    <!-- Empty → cần tạo -->
    <div v-else-if="!plan && !generating" class="mx-5 mt-6 flex flex-col items-center text-center">
      <CaloeyeCharacter mood="motivate" :size="96" />
      <p class="text-[16px] font-semibold text-black mt-3">Chưa có kế hoạch {{ SCOPE_LABEL[scope] }}</p>
      <p class="text-[13px] text-ios-gray mt-1 px-6">Để AI lập kế hoạch ăn uống &amp; tập luyện dựa trên dữ liệu của bạn.</p>
      <button class="mt-4 px-6 h-12 rounded-[14px] bg-calor-green text-white text-[16px] font-semibold ios-press" @click="generate(scope)">
        Tạo kế hoạch
      </button>
    </div>

    <!-- Content -->
    <div v-else class="px-5 space-y-4">
      <!-- Bản xem trước: chưa lưu, kế hoạch cũ vẫn còn nguyên cho tới khi bấm Áp dụng -->
      <div v-if="isDraft" class="bg-ios-blue/8 border border-ios-blue/25 rounded-[14px] px-4 py-3 flex items-center gap-3">
        <span class="text-xl">👀</span>
        <div class="flex-1 min-w-0">
          <p class="text-[13px] font-medium text-black">Bản xem trước</p>
          <p class="text-[12px] text-ios-gray">Chưa áp dụng. Bấm “Áp dụng kế hoạch” bên dưới để bắt đầu theo kế hoạch này.</p>
        </div>
      </div>

      <!-- Stale banner -->
      <div v-if="isStale" class="bg-ios-orange/10 border border-ios-orange/30 rounded-[14px] px-4 py-3 flex items-center gap-3">
        <span class="text-xl">🔄</span>
        <div class="flex-1">
          <p class="text-[13px] font-medium text-black">Dữ liệu đã thay đổi</p>
          <p class="text-[12px] text-ios-gray">Cập nhật để kế hoạch sát hơn.</p>
        </div>
        <button class="text-[13px] text-ios-blue font-semibold ios-press" :disabled="generating" @click="generate(scope)">
          Cập nhật
        </button>
      </div>

      <!-- Generating indicator -->
      <div v-if="generating && !plan" class="bg-white rounded-[18px] px-5 py-6 flex items-center gap-3">
        <div class="w-5 h-5 rounded-full border-2 border-calor-green border-t-transparent animate-spin"/>
        <span class="text-[14px] text-ios-gray">AI đang lập kế hoạch…</span>
      </div>

      <!-- ── DAILY ── -->
      <template v-if="dailyPlan">
        <!-- Target card (gradient matcha) -->
        <div class="relative overflow-hidden rounded-[22px] px-5 py-4 text-white bg-gradient-to-br from-[var(--color-matcha-mid)] to-[var(--color-calor-dark)] shadow-lg shadow-matcha/25">
          <div class="absolute -right-4 -top-3 text-[92px] leading-none opacity-[0.14] pointer-events-none select-none">🥑</div>
          <p class="relative text-[13px] text-white/90 leading-snug">{{ dailyPlan.summary }}</p>
          <div class="relative flex items-baseline gap-2 mt-2">
            <span class="font-display text-[34px] font-bold leading-none">{{ dailyPlan.target_calories.toLocaleString('vi') }}</span>
            <span class="text-[12px] text-white/85">kcal mục tiêu</span>
          </div>
          <div class="relative flex gap-3.5 mt-2 text-[12px] text-white/85">
            <span>P {{ dailyPlan.target_macros.protein }}g</span>
            <span>C {{ dailyPlan.target_macros.carbs }}g</span>
            <span>F {{ dailyPlan.target_macros.fat }}g</span>
            <span>💧 {{ (dailyPlan.water_target_ml / 1000).toFixed(1) }}L</span>
          </div>
        </div>

        <!-- Vertical timeline: bữa ăn + tập luyện -->
        <div class="relative pl-[26px]">
          <div class="absolute left-[9px] top-3 bottom-3 w-0.5 bg-[var(--color-line)]"/>
          <div v-for="(m, i) in dailyPlan.meals" :key="'m' + i" class="relative mb-3">
            <div class="absolute -left-[26px] top-4 w-5 h-5 rounded-full bg-calor-light border-[3px] border-[var(--color-calor-bg)] grid place-items-center text-[10px]">{{ SLOT_ICON[m.slot] }}</div>
            <div class="bg-white rounded-[18px] px-4 py-3.5 shadow-[0_8px_22px_rgba(60,74,52,0.06)]">
              <div class="flex items-center justify-between">
                <b class="text-[13px] font-semibold text-calor-deep">{{ SLOT_LABEL[m.slot] ?? m.name }}</b>
                <span class="text-[12px] font-semibold text-calor-dark">{{ m.calories }} kcal</span>
              </div>
              <p class="text-[12px] text-[#4a5545] mt-0.5">{{ m.name }}</p>
              <p v-if="m.items?.length" class="text-[11px] text-ios-gray mt-0.5">{{ m.items.join(' · ') }}</p>
            </div>
          </div>
          <div v-for="(w, i) in (dailyPlan.workouts ?? [])" :key="'w' + i" class="relative mb-3">
            <div class="absolute -left-[26px] top-4 w-5 h-5 rounded-full bg-[#f6e2cd] border-[3px] border-[var(--color-calor-bg)] grid place-items-center text-[10px]">{{ WORKOUT_ICON[w.type] }}</div>
            <div class="bg-white rounded-[18px] px-4 py-3.5 shadow-[0_8px_22px_rgba(60,74,52,0.06)]">
              <div class="flex items-center justify-between">
                <b class="text-[13px] font-semibold text-calor-deep">{{ w.name }}</b>
                <span class="text-[12px] font-semibold text-[#d98c5f]">-{{ w.est_calories_burned }} kcal</span>
              </div>
              <p class="text-[12px] text-[#4a5545] mt-0.5">{{ w.duration_min }} phút · cường độ {{ w.intensity }}</p>
            </div>
          </div>
        </div>

        <!-- AI tips -->
        <div v-if="dailyPlan.tips?.length" class="rounded-[16px] bg-[#f7faf3] border border-[#e0e6d6] px-4 py-3.5 flex gap-2.5">
          <div class="w-7 h-7 rounded-full bg-matcha grid place-items-center text-white text-xs flex-shrink-0">🤖</div>
          <ul class="space-y-1 min-w-0">
            <li v-for="(t, i) in dailyPlan.tips" :key="i" class="text-[12px] text-[#4a5545] leading-relaxed">{{ t }}</li>
          </ul>
        </div>
      </template>

      <!-- ── WEEKLY ── -->
      <template v-else-if="weeklyPlan">
        <div class="bg-gradient-to-br from-[#dfeacf] to-[#c6ddc0] rounded-[18px] px-5 py-4">
          <p class="text-[14px] text-calor-deep font-medium leading-snug">{{ weeklyPlan.summary }}</p>
          <p class="text-[12px] text-calor-dark mt-2">Kế hoạch 7 ngày · nhiệm vụ hằng ngày sẽ đổi theo từng ngày</p>
        </div>

        <!-- 7 ngày -->
        <div
          v-for="(d, i) in weeklyPlan.days"
          :key="i"
          class="bg-white rounded-[18px] overflow-hidden"
          :class="d.weekday === todayIso ? 'ring-2 ring-calor-green' : ''"
        >
          <div class="px-4 py-3 flex items-center justify-between border-b border-ios-gray6">
            <div class="flex items-center gap-2">
              <span class="text-[15px] font-semibold text-black">{{ d.label }}</span>
              <span v-if="d.weekday === todayIso" class="text-[11px] font-semibold text-calor-green bg-calor-light px-2 py-0.5 rounded-full">Hôm nay</span>
            </div>
            <span class="text-[13px] text-ios-gray">{{ d.target_calories?.toLocaleString('vi') }} kcal</span>
          </div>

          <p v-if="d.focus" class="px-4 pt-2 text-[12px] text-calor-dark">🎯 {{ d.focus }}</p>

          <!-- Bữa ăn -->
          <div class="px-4 py-2 divide-y divide-ios-gray6">
            <div v-for="(m, j) in d.meals" :key="j" class="py-2">
              <div class="flex items-center justify-between">
                <span class="text-[13px] font-medium text-black">{{ SLOT_ICON[m.slot] }} {{ SLOT_LABEL[m.slot] ?? m.name }}</span>
                <span class="text-[12px] text-ios-gray">{{ m.calories }} kcal</span>
              </div>
              <p class="text-[12px] text-black mt-0.5">{{ m.name }}</p>
              <p v-if="m.items?.length" class="text-[11px] text-ios-gray mt-0.5">{{ m.items.join(' · ') }}</p>
            </div>
          </div>

          <!-- Buổi tập -->
          <div v-if="d.workout" class="px-4 py-2.5 flex items-center justify-between bg-ios-gray6/40">
            <div>
              <p class="text-[13px] font-medium text-black">{{ WORKOUT_ICON[d.workout.type] }} {{ d.workout.name }}</p>
              <p class="text-[11px] text-ios-gray mt-0.5">{{ d.workout.duration_min }} phút · cường độ {{ d.workout.intensity }}</p>
            </div>
            <span class="text-[12px] text-ios-orange font-medium">-{{ d.workout.est_calories_burned }} kcal</span>
          </div>
        </div>

        <div v-if="weeklyPlan.tips?.length" class="bg-white rounded-[18px] px-4 py-3">
          <h2 class="text-[13px] font-semibold text-ios-gray uppercase tracking-wider mb-2">Lời khuyên</h2>
          <ul class="space-y-1.5">
            <li v-for="(t, i) in weeklyPlan.tips" :key="i" class="text-[13px] text-black flex gap-2">
              <span class="text-calor-green">•</span><span>{{ t }}</span>
            </li>
          </ul>
        </div>
      </template>

      <!-- ── MONTHLY ── -->
      <template v-else-if="monthlyPlan">
        <div class="bg-gradient-to-br from-[var(--color-calor-green)]/12 to-[var(--color-calor-dark)]/10 rounded-[18px] px-5 py-4">
          <p class="text-[14px] text-black font-medium leading-snug">{{ monthlyPlan.summary }}</p>
          <div class="flex gap-4 mt-3 text-[13px] text-ios-gray">
            <span><strong class="text-black">{{ monthlyPlan.avg_daily_calories.toLocaleString('vi') }}</strong> kcal TB/ngày</span>
            <span>Dự kiến <strong class="text-black">{{ monthlyPlan.expected_weight_change_kg > 0 ? '+' : '' }}{{ monthlyPlan.expected_weight_change_kg }}kg</strong></span>
          </div>
        </div>

        <div v-if="monthlyPlan.weekly_focus?.length">
          <h2 class="text-[13px] font-semibold text-ios-gray uppercase tracking-wider mb-2">Trọng tâm theo tuần</h2>
          <div class="bg-white rounded-[18px] divide-y divide-ios-gray6 overflow-hidden">
            <div v-for="(w, i) in monthlyPlan.weekly_focus" :key="i" class="px-4 py-3">
              <p class="text-[14px] font-semibold text-black">Tuần {{ w.week }}: {{ w.focus }}</p>
              <p class="text-[12px] text-ios-gray mt-0.5">{{ w.note }}</p>
            </div>
          </div>
        </div>

        <div v-if="monthlyPlan.weekly_workout_split?.length">
          <h2 class="text-[13px] font-semibold text-ios-gray uppercase tracking-wider mb-2">Lịch tập tuần</h2>
          <div class="bg-white rounded-[18px] divide-y divide-ios-gray6 overflow-hidden">
            <div v-for="(d, i) in monthlyPlan.weekly_workout_split" :key="i" class="px-4 py-3 flex items-center justify-between">
              <span class="text-[14px] text-black font-medium">{{ d.day }}</span>
              <span class="text-[13px] text-ios-gray">{{ d.activity }} · {{ d.duration_min }}'</span>
            </div>
          </div>
        </div>

        <div v-if="monthlyPlan.tips?.length" class="bg-white rounded-[18px] px-4 py-3">
          <h2 class="text-[13px] font-semibold text-ios-gray uppercase tracking-wider mb-2">Lời khuyên</h2>
          <ul class="space-y-1.5">
            <li v-for="(t, i) in monthlyPlan.tips" :key="i" class="text-[13px] text-black flex gap-2">
              <span class="text-calor-green">•</span><span>{{ t }}</span>
            </li>
          </ul>
        </div>
      </template>

      <!-- Reasoning (stream) -->
      <div v-if="reasoning" class="bg-gradient-to-r from-ios-blue/5 to-ios-purple/5 border border-ios-blue/15 rounded-[18px] p-4">
        <div class="flex gap-3">
          <div class="w-8 h-8 rounded-full bg-ios-blue flex items-center justify-center flex-shrink-0 mt-0.5">
            <svg viewBox="0 0 24 24" class="w-4 h-4" fill="white"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>
          </div>
          <div>
            <p class="text-[13px] font-semibold text-black">Vì sao kế hoạch này</p>
            <p class="text-[13px] text-ios-gray mt-0.5 leading-relaxed whitespace-pre-wrap">{{ reasoning }}</p>
          </div>
        </div>
      </div>

      <!-- Áp dụng (bản nháp) / Tạo lại -->
      <div v-if="plan && !generating" class="space-y-2.5">
        <button
          v-if="isDraft"
          class="w-full h-12 rounded-[14px] bg-calor-green text-white text-[16px] font-semibold ios-press disabled:opacity-60"
          :disabled="applying"
          @click="applyPlan"
        >
          {{ applying ? 'Đang áp dụng…' : 'Áp dụng kế hoạch' }}
        </button>

        <button
          class="w-full h-11 rounded-[14px] border border-ios-gray4 text-[14px] text-ios-blue font-medium ios-press"
          :disabled="applying"
          @click="generate(scope)"
        >
          Tạo lại kế hoạch
        </button>

        <!-- Đã là kế hoạch đang theo → mở trang tiến độ -->
        <NuxtLink
          v-if="!isDraft"
          to="/plan/progress"
          class="w-full h-11 rounded-[14px] bg-white border border-ios-gray5 text-[14px] text-black font-medium ios-press flex items-center justify-center gap-2"
        >
          📈 Xem tiến độ thực hiện
        </NuxtLink>
      </div>
    </div>
  </div>
</template>
