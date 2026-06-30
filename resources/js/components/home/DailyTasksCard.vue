<script setup lang="ts">
import { useWater } from '@/composables/useWater'
import { apiFetch } from '@/utils/api'
import { navigateTo } from '@/utils/navigate'

const props = defineProps<{
  mealLogged: boolean
  streakAtRisk?: boolean
}>()

const { totalMl, isCompleted: waterCompleted, percentage: waterPct, logWater } = useWater()

const QUICK_AMOUNTS = [150, 250, 500]

interface WorkoutTask {
  name: string
  type: string | null
  duration_min: number | null
  done: boolean
}

// Nhiệm vụ tập luyện cá nhân hóa theo kế hoạch AI (null = chưa có kế hoạch).
const workout = ref<WorkoutTask | null>(null)
const hasPlan = ref(true)   // mặc định true để tránh CTA nhấp nháy trước khi tải xong

onMounted(async () => {
  try {
    const r = await apiFetch<{ has_plan: boolean; workout: WorkoutTask | null }>('/home/daily-tasks')
    hasPlan.value = r.has_plan
    workout.value = r.workout
  } catch {
    // Không tải được kế hoạch → giữ task mặc định, không chặn UI
  }
})

const workoutSubtitle = computed(() => {
  const w = workout.value
  if (!w) return ''
  const parts: string[] = []
  if (w.duration_min) parts.push(`${w.duration_min} phút`)
  if (w.type) parts.push(w.type)
  return parts.join(' · ') || 'Theo kế hoạch hôm nay'
})

const allDone = computed(() =>
  props.mealLogged
  && waterCompleted.value
  && (!workout.value || workout.value.done),
)
</script>

<template>
  <div class="mx-5 mb-4 bg-white rounded-[20px] overflow-hidden shadow-sm animate-fadeInUp delay-2" style="opacity:0">
    <div class="px-5 pt-4 pb-1">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-[13px] font-semibold text-ios-gray uppercase tracking-wider">Nhiệm vụ hôm nay</h2>
        <span v-if="allDone" class="text-[12px] font-semibold text-calor-green">Hoàn thành! 🎉</span>
      </div>
    </div>

    <!-- Task 1: Ghi lại bữa ăn -->
    <div class="flex items-center gap-3 px-5 pb-3">
      <div
        class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 transition-colors"
        :class="mealLogged ? 'bg-calor-green' : 'bg-ios-gray6'"
      >
        <svg v-if="mealLogged" viewBox="0 0 24 24" class="w-4 h-4" fill="white">
          <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
        </svg>
        <span v-else class="text-[14px]">🍽️</span>
      </div>
      <div class="flex-1">
        <p class="text-[14px] font-medium text-black">Ghi lại bữa ăn</p>
        <p class="text-[12px] text-ios-gray">{{ mealLogged ? 'Đã hoàn thành' : 'Chụp ảnh hoặc nhập tay' }}</p>
      </div>
      <span v-if="!mealLogged" class="text-[12px] text-ios-gray3">+🥑</span>
    </div>

    <div class="ios-separator mx-5" />

    <!-- Task 2: Uống nước -->
    <div class="px-5 py-3">
      <div class="flex items-center gap-3 mb-2.5">
        <div
          class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 transition-colors"
          :class="waterCompleted ? 'bg-ios-blue' : 'bg-ios-gray6'"
        >
          <svg v-if="waterCompleted" viewBox="0 0 24 24" class="w-4 h-4" fill="white">
            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
          </svg>
          <span v-else class="text-[14px]">💧</span>
        </div>
        <div class="flex-1">
          <p class="text-[14px] font-medium text-black">Uống đủ nước</p>
          <p class="text-[12px] text-ios-gray">
            {{ waterCompleted ? 'Đạt 2 lít rồi! 💧' : `${totalMl} / 2000 ml` }}
          </p>
        </div>
        <span class="text-[12px] font-semibold" :class="waterCompleted ? 'text-ios-blue' : 'text-ios-gray3'">
          {{ Math.round(waterPct) }}%
        </span>
      </div>

      <!-- Progress bar nước -->
      <div class="h-2 bg-ios-gray6 rounded-full overflow-hidden mb-3">
        <div
          class="h-full rounded-full transition-all duration-500"
          :class="waterCompleted ? 'bg-ios-blue' : 'bg-ios-blue/60'"
          :style="`width: ${waterPct}%`"
        />
      </div>

      <!-- Quick add buttons -->
      <div v-if="!waterCompleted" class="flex gap-2">
        <button
          v-for="ml in QUICK_AMOUNTS"
          :key="ml"
          class="flex-1 py-1.5 rounded-[10px] bg-ios-blue/10 text-ios-blue text-[12px] font-semibold ios-press"
          @click="logWater(ml)"
        >
          +{{ ml }}ml
        </button>
      </div>
    </div>

    <!-- Task 3: Tập luyện theo kế hoạch AI (chỉ khi có kế hoạch) -->
    <template v-if="workout">
      <div class="ios-separator mx-5" />
      <div class="flex items-center gap-3 px-5 py-3">
        <div
          class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 transition-colors"
          :class="workout.done ? 'bg-calor-green' : 'bg-ios-gray6'"
        >
          <svg v-if="workout.done" viewBox="0 0 24 24" class="w-4 h-4" fill="white">
            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
          </svg>
          <span v-else class="text-[14px]">🏃</span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-[14px] font-medium text-black truncate">{{ workout.name }}</p>
          <p class="text-[12px] text-ios-gray">{{ workout.done ? 'Đã hoàn thành' : workoutSubtitle }}</p>
        </div>
        <span v-if="!workout.done" class="text-[12px] text-ios-gray3">🔥</span>
      </div>
    </template>

    <!-- Chưa có kế hoạch → gợi ý tạo để có nhiệm vụ tập luyện cá nhân hóa -->
    <template v-else-if="!hasPlan">
      <div class="ios-separator mx-5" />
      <button
        class="w-full flex items-center gap-3 px-5 py-3 text-left ios-press"
        @click="navigateTo('/plan')"
      >
        <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 bg-calor-light">
          <span class="text-[14px]">✨</span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-[14px] font-medium text-calor-deep">Tạo kế hoạch tập luyện</p>
          <p class="text-[12px] text-ios-gray">Để AI gợi ý nhiệm vụ phù hợp với bạn</p>
        </div>
        <span class="text-[12px] text-ios-gray3">›</span>
      </button>
    </template>
  </div>
</template>
