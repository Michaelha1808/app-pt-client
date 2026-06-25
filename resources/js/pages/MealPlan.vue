<script setup lang="ts">
import CaloeyeCharacter from '@/components/caloeye/Character.vue'
import { useMealPlan } from '@/composables/useMealPlan'
import type { DailyPlan, MonthlyPlan, PlanScope } from '@/types/plan'

const { plan, reasoning, isStale, loading, generating, error, fetchPlan, generate } = useMealPlan()

const scope = ref<PlanScope>('daily')

const dailyPlan   = computed(() => (scope.value === 'daily' ? plan.value as DailyPlan | null : null))
const monthlyPlan = computed(() => (scope.value === 'monthly' ? plan.value as MonthlyPlan | null : null))

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
  <div class="flex flex-col bg-[#F2F2F7] min-h-full pb-6">
    <!-- Header -->
    <div class="px-5 pt-3 pb-2">
      <h1 class="text-[22px] font-bold text-black">Kế hoạch của bạn</h1>
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
      <p class="text-[16px] font-semibold text-black mt-3">Chưa có kế hoạch {{ scope === 'daily' ? 'ngày mai' : 'tháng này' }}</p>
      <p class="text-[13px] text-ios-gray mt-1 px-6">Để AI lập kế hoạch ăn uống &amp; tập luyện dựa trên dữ liệu của bạn.</p>
      <button class="mt-4 px-6 h-12 rounded-[14px] bg-calor-green text-white text-[16px] font-semibold ios-press" @click="generate(scope)">
        Tạo kế hoạch
      </button>
    </div>

    <!-- Content -->
    <div v-else class="px-5 space-y-4">
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
        <div class="bg-gradient-to-br from-calor-light to-[#C8F0E2] rounded-[18px] px-5 py-4">
          <p class="text-[14px] text-calor-deep font-medium leading-snug">{{ dailyPlan.summary }}</p>
          <div class="flex items-end gap-1 mt-3">
            <span class="text-[28px] font-bold text-calor-deep leading-none">{{ dailyPlan.target_calories.toLocaleString('vi') }}</span>
            <span class="text-[13px] text-calor-dark mb-0.5">kcal mục tiêu</span>
          </div>
          <div class="flex gap-4 mt-2 text-[12px] text-calor-dark">
            <span>P {{ dailyPlan.target_macros.protein }}g</span>
            <span>C {{ dailyPlan.target_macros.carbs }}g</span>
            <span>F {{ dailyPlan.target_macros.fat }}g</span>
            <span>💧 {{ dailyPlan.water_target_ml }}ml</span>
          </div>
        </div>

        <!-- Meals -->
        <div>
          <h2 class="text-[13px] font-semibold text-ios-gray uppercase tracking-wider mb-2">Bữa ăn</h2>
          <div class="bg-white rounded-[18px] divide-y divide-ios-gray6 overflow-hidden">
            <div v-for="(m, i) in dailyPlan.meals" :key="i" class="px-4 py-3">
              <div class="flex items-center justify-between">
                <span class="text-[14px] font-semibold text-black">{{ SLOT_ICON[m.slot] }} {{ SLOT_LABEL[m.slot] ?? m.name }}</span>
                <span class="text-[13px] text-ios-gray">{{ m.calories }} kcal</span>
              </div>
              <p class="text-[13px] text-black mt-1">{{ m.name }}</p>
              <p v-if="m.items?.length" class="text-[12px] text-ios-gray mt-0.5">{{ m.items.join(' · ') }}</p>
            </div>
          </div>
        </div>

        <!-- Workouts -->
        <div v-if="dailyPlan.workouts?.length">
          <h2 class="text-[13px] font-semibold text-ios-gray uppercase tracking-wider mb-2">Tập luyện</h2>
          <div class="bg-white rounded-[18px] divide-y divide-ios-gray6 overflow-hidden">
            <div v-for="(w, i) in dailyPlan.workouts" :key="i" class="px-4 py-3 flex items-center justify-between">
              <div>
                <p class="text-[14px] font-medium text-black">{{ WORKOUT_ICON[w.type] }} {{ w.name }}</p>
                <p class="text-[12px] text-ios-gray mt-0.5">{{ w.duration_min }} phút · cường độ {{ w.intensity }}</p>
              </div>
              <span class="text-[13px] text-ios-orange font-medium">-{{ w.est_calories_burned }} kcal</span>
            </div>
          </div>
        </div>

        <div v-if="dailyPlan.tips?.length" class="bg-white rounded-[18px] px-4 py-3">
          <h2 class="text-[13px] font-semibold text-ios-gray uppercase tracking-wider mb-2">Lời khuyên</h2>
          <ul class="space-y-1.5">
            <li v-for="(t, i) in dailyPlan.tips" :key="i" class="text-[13px] text-black flex gap-2">
              <span class="text-calor-green">•</span><span>{{ t }}</span>
            </li>
          </ul>
        </div>
      </template>

      <!-- ── MONTHLY ── -->
      <template v-else-if="monthlyPlan">
        <div class="bg-gradient-to-br from-ios-blue/10 to-ios-purple/10 rounded-[18px] px-5 py-4">
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

      <!-- Tạo lại -->
      <button
        v-if="plan && !generating"
        class="w-full h-11 rounded-[14px] border border-ios-gray4 text-[14px] text-ios-blue font-medium ios-press"
        @click="generate(scope)"
      >
        Tạo lại kế hoạch
      </button>
    </div>
  </div>
</template>
