<script setup lang="ts">
import { useRouter } from 'vue-router'
import CaloeyeCharacter from '@/components/caloeye/Character.vue'
import { usePlanProgress } from '@/composables/usePlanProgress'

const router = useRouter()
const { progress, loading, error, fetchProgress } = usePlanProgress()

const SLOT_LABEL: Record<string, string> = {
  breakfast: 'Bữa sáng', lunch: 'Bữa trưa', dinner: 'Bữa tối', snack: 'Bữa phụ',
}
const SLOT_ICON: Record<string, string> = {
  breakfast: '🌅', lunch: '🍚', dinner: '🌙', snack: '🍎',
}

// Vòng tròn tiến độ: bán kính 52 → chu vi ≈ 326.7 dùng cho stroke-dasharray
const RING_RADIUS = 52
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS

const todayPercent = computed(() => progress.value?.today.percent ?? 0)
const ringOffset = computed(() => RING_CIRCUMFERENCE * (1 - todayPercent.value / 100))

// Màu đổi theo mức hoàn thành — nhìn là biết đang tốt hay cần cố thêm
const ringColor = computed(() => {
  const p = todayPercent.value
  if (p >= 100) return 'var(--color-calor-green)'
  if (p >= 50)  return 'var(--color-matcha-mid)'
  if (p > 0)    return '#e8a33d'
  return '#c9d2c0'
})

const hasTasks = computed(() => (progress.value?.today.total ?? 0) > 0)

onMounted(fetchProgress)
</script>

<template>
  <div class="flex flex-col bg-[var(--color-calor-bg)] min-h-full pb-24">
    <!-- Header -->
    <div class="flex items-center gap-3 px-4 pt-2 pb-4">
      <button class="ios-press p-1 -ml-1" aria-label="Quay lại" @click="router.back()">
        <svg viewBox="0 0 24 24" class="w-6 h-6" style="fill:var(--color-calor-green)">
          <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
        </svg>
      </button>
      <h1 class="text-[17px] font-semibold text-black">Tiến độ kế hoạch</h1>
    </div>

    <!-- Loading -->
    <div v-if="loading && !progress" class="mx-5 space-y-3">
      <div class="bg-white rounded-[22px] h-56 animate-pulse"/>
      <div class="bg-white rounded-[18px] h-24 animate-pulse"/>
      <div class="bg-white rounded-[18px] h-32 animate-pulse"/>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="mx-5 bg-ios-red/8 border border-ios-red/25 rounded-[18px] px-5 py-5 text-center">
      <CaloeyeCharacter mood="warning" :size="64" />
      <p class="text-[14px] text-ios-red mt-2">{{ error }}</p>
      <button class="mt-3 px-5 h-10 rounded-full bg-ios-blue text-white text-[14px] font-semibold ios-press" @click="fetchProgress">
        Thử lại
      </button>
    </div>

    <template v-else-if="progress">
      <!-- ── Vòng tiến độ hôm nay ── -->
      <div class="mx-5 bg-white rounded-[22px] px-5 py-6 shadow-[0_8px_22px_rgba(60,74,52,0.06)] flex flex-col items-center">
        <p class="text-[13px] text-ios-gray uppercase tracking-wider font-medium">Hôm nay</p>

        <div class="relative mt-3 w-[132px] h-[132px]">
          <svg viewBox="0 0 132 132" class="w-full h-full -rotate-90">
            <circle cx="66" cy="66" :r="RING_RADIUS" fill="none" stroke="var(--color-line)" stroke-width="12"/>
            <circle
              cx="66" cy="66" :r="RING_RADIUS" fill="none"
              :stroke="ringColor" stroke-width="12" stroke-linecap="round"
              :stroke-dasharray="RING_CIRCUMFERENCE"
              :stroke-dashoffset="ringOffset"
              class="transition-[stroke-dashoffset] duration-700 ease-out"
            />
          </svg>
          <div class="absolute inset-0 flex flex-col items-center justify-center">
            <span class="font-display text-[34px] font-bold leading-none text-black">{{ todayPercent }}<span class="text-[18px]">%</span></span>
            <span v-if="hasTasks" class="text-[12px] text-ios-gray mt-1">{{ progress.today.done }}/{{ progress.today.total }} nhiệm vụ</span>
          </div>
        </div>

        <!-- Tiến độ tuần -->
        <div class="w-full mt-5">
          <div class="flex items-center justify-between mb-1.5">
            <span class="text-[13px] font-medium text-black">Tuần này</span>
            <span class="text-[13px] font-semibold text-calor-dark">{{ progress.week.percent }}%</span>
          </div>
          <div class="h-2.5 rounded-full bg-[var(--color-line)] overflow-hidden">
            <div
              class="h-full rounded-full bg-gradient-to-r from-[var(--color-matcha-mid)] to-[var(--color-calor-green)] transition-[width] duration-700 ease-out"
              :style="{ width: `${progress.week.percent}%` }"
            />
          </div>
          <p class="text-[11px] text-ios-gray mt-1">
            {{ progress.week.done }}/{{ progress.week.total }} nhiệm vụ đã hoàn thành từ đầu tuần
          </p>
        </div>
      </div>

      <!-- ── Lời động viên ── -->
      <div class="mx-5 mt-3 rounded-[18px] bg-gradient-to-br from-[#f7faf3] to-[#eef4e6] border border-[#e0e6d6] px-4 py-4 flex gap-3">
        <div class="text-[30px] leading-none flex-shrink-0">{{ progress.encouragement.emoji }}</div>
        <div class="min-w-0">
          <p class="text-[15px] font-semibold text-calor-deep">{{ progress.encouragement.title }}</p>
          <p class="text-[13px] text-[#4a5545] mt-1 leading-relaxed">{{ progress.encouragement.message }}</p>
        </div>
      </div>

      <!-- ── Biểu đồ 7 ngày ── -->
      <div class="mx-5 mt-3 bg-white rounded-[18px] px-4 py-4 shadow-[0_8px_22px_rgba(60,74,52,0.06)]">
        <h2 class="text-[13px] font-semibold text-ios-gray uppercase tracking-wider mb-3">7 ngày qua</h2>
        <div class="flex items-end justify-between gap-1.5 h-[110px]">
          <div v-for="d in progress.week.days" :key="d.date" class="flex-1 flex flex-col items-center gap-1.5 h-full">
            <div class="flex-1 w-full flex items-end">
              <!-- Ngày chưa tới: cột mờ nét đứt, không tính vào % -->
              <div
                v-if="d.is_future"
                class="w-full rounded-t-[6px] border border-dashed border-[var(--color-line)] bg-transparent"
                style="height: 12px"
              />
              <!-- Dùng cùng 1 token xanh + độ mờ (không dùng matcha-light: theme "green" map
                   token đó sang xanh DƯƠNG nên cột sẽ lệch tông) -->
              <div
                v-else
                class="w-full rounded-t-[6px] transition-[height] duration-700 ease-out"
                :class="d.is_today ? 'bg-calor-green' : 'bg-calor-green/40'"
                :style="{ height: `${Math.max(6, (d.percent ?? 0))}%` }"
                :title="`${d.done ?? 0}/${d.total ?? 0} nhiệm vụ`"
              />
            </div>
            <span class="text-[10px]" :class="d.is_today ? 'text-calor-green font-semibold' : 'text-ios-gray'">{{ d.label }}</span>
          </div>
        </div>
      </div>

      <!-- ── Nhiệm vụ hôm nay ── -->
      <div v-if="hasTasks" class="mx-5 mt-3 bg-white rounded-[18px] overflow-hidden shadow-[0_8px_22px_rgba(60,74,52,0.06)]">
        <h2 class="px-4 pt-4 pb-2 text-[13px] font-semibold text-ios-gray uppercase tracking-wider">Nhiệm vụ hôm nay</h2>

        <div class="divide-y divide-ios-gray6">
          <div v-for="(m, i) in progress.today.meals" :key="'m' + i" class="px-4 py-3 flex items-center gap-3">
            <div
              class="w-6 h-6 rounded-full grid place-items-center flex-shrink-0 text-[11px]"
              :class="m.done ? 'bg-calor-green text-white' : 'bg-ios-gray6 text-ios-gray3'"
            >
              <svg v-if="m.done" viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="currentColor"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
              <span v-else>{{ SLOT_ICON[m.slot] }}</span>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-[13px] font-medium" :class="m.done ? 'text-ios-gray line-through' : 'text-black'">
                {{ SLOT_LABEL[m.slot] ?? 'Bữa ăn' }}
              </p>
              <p class="text-[12px] text-ios-gray truncate">{{ m.name }}</p>
            </div>
            <span v-if="m.calories" class="text-[12px] text-ios-gray flex-shrink-0">{{ m.calories }} kcal</span>
          </div>

          <div v-if="progress.today.workout" class="px-4 py-3 flex items-center gap-3">
            <div
              class="w-6 h-6 rounded-full grid place-items-center flex-shrink-0 text-[11px]"
              :class="progress.today.workout.done ? 'bg-calor-green text-white' : 'bg-ios-gray6 text-ios-gray3'"
            >
              <svg v-if="progress.today.workout.done" viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="currentColor"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
              <span v-else>💪</span>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-[13px] font-medium" :class="progress.today.workout.done ? 'text-ios-gray line-through' : 'text-black'">
                Buổi tập
              </p>
              <p class="text-[12px] text-ios-gray truncate">{{ progress.today.workout.name }}</p>
            </div>
            <span v-if="progress.today.workout.duration_min" class="text-[12px] text-ios-gray flex-shrink-0">
              {{ progress.today.workout.duration_min }} phút
            </span>
          </div>
        </div>

        <p class="px-4 py-3 text-[11px] text-ios-gray border-t border-ios-gray6">
          Nhiệm vụ tự đánh dấu hoàn thành khi bạn ghi lại bữa ăn hoặc buổi tập tương ứng.
        </p>
      </div>

      <!-- Chưa có kế hoạch nào -->
      <div v-else class="mx-5 mt-3 bg-white rounded-[18px] px-5 py-8 flex flex-col items-center text-center shadow-[0_8px_22px_rgba(60,74,52,0.06)]">
        <CaloeyeCharacter mood="motivate" :size="80" />
        <p class="text-[15px] font-semibold text-black mt-3">Chưa có nhiệm vụ nào hôm nay</p>
        <p class="text-[13px] text-ios-gray mt-1">Tạo kế hoạch để mình theo dõi tiến độ cùng bạn nhé.</p>
        <NuxtLink to="/plan" class="mt-4 px-6 h-11 rounded-[14px] bg-calor-green text-white text-[15px] font-semibold ios-press flex items-center">
          Tạo kế hoạch
        </NuxtLink>
      </div>
    </template>
  </div>
</template>
