<script setup lang="ts">
import { useWater } from '@/composables/useWater'

const props = defineProps<{
  mealLogged: boolean
  streakAtRisk?: boolean
}>()

const { totalMl, isCompleted: waterCompleted, percentage: waterPct, logWater } = useWater()

const QUICK_AMOUNTS = [150, 250, 500]

const allDone = computed(() => props.mealLogged && waterCompleted.value)
</script>

<template>
  <div class="mx-5 mb-4 bg-white rounded-[20px] overflow-hidden shadow-sm animate-fadeInUp delay-2" style="opacity:0">
    <div class="px-5 pt-4 pb-1">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-[13px] font-semibold text-ios-gray uppercase tracking-wider">Nhiệm vụ hôm nay</h2>
        <span v-if="allDone" class="text-[12px] font-semibold text-calor-green">Hoàn thành! 🎉</span>
      </div>
    </div>

    <!-- Task 1: Log bữa ăn -->
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
        <p class="text-[14px] font-medium text-black">Log bữa ăn</p>
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
  </div>
</template>
