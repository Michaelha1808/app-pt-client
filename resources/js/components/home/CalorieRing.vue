<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

const props = defineProps<{
  consumed: number
  goal: number
  burned?: number
  size?: number
}>()

const animated = ref(false)
const radius = 90
const circumference = 2 * Math.PI * radius  // ≈ 565.5

// Net calories: calo đốt (tập luyện) bù lại budget → net = nạp − đốt.
const burned = computed(() => props.burned ?? 0)
const net = computed(() => props.consumed - burned.value)

const progress = computed(() => Math.min(Math.max(net.value / props.goal, 0), 1))
const remaining = computed(() => Math.max(props.goal - net.value, 0))
const offset = computed(() => circumference * (1 - (animated.value ? progress.value : 0)))

const ringColor = computed(() => {
  if (progress.value >= 1) return '#FF3B30'      // exceeded – red
  if (progress.value >= 0.85) return '#FF9500'   // near limit – orange
  return 'url(#ringGradient)'                     // normal – blue-green
})

onMounted(() => {
  requestAnimationFrame(() => setTimeout(() => (animated.value = true), 100))
})
</script>

<template>
  <div class="flex flex-col items-center">
    <div class="relative" :style="`width: ${size ?? 220}px; height: ${size ?? 220}px`">
      <svg
        :width="size ?? 220"
        :height="size ?? 220"
        viewBox="0 0 220 220"
        class="rotate-[-90deg]"
      >
        <defs>
          <linearGradient id="ringGradient" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%"   stop-color="#007AFF"/>
            <stop offset="100%" stop-color="#34C759"/>
          </linearGradient>
        </defs>

        <!-- Track -->
        <circle
          cx="110" cy="110" :r="radius"
          fill="none"
          stroke="#E5E5EA"
          stroke-width="16"
          stroke-linecap="round"
        />

        <!-- Progress arc -->
        <circle
          cx="110" cy="110" :r="radius"
          fill="none"
          :stroke="ringColor"
          stroke-width="16"
          stroke-linecap="round"
          :stroke-dasharray="circumference"
          :stroke-dashoffset="offset"
          style="transition: stroke-dashoffset 1.2s cubic-bezier(0.16,1,0.3,1), stroke 0.4s ease"
        />
      </svg>

      <!-- Center content -->
      <div class="absolute inset-0 flex flex-col items-center justify-center">
        <span class="text-[11px] font-medium text-ios-gray uppercase tracking-wider">Còn lại</span>
        <span
          class="text-[46px] font-bold leading-none mt-0.5 tabular-nums"
          :class="progress >= 1 ? 'text-ios-red' : 'text-black'"
        >{{ remaining.toLocaleString('vi') }}</span>
        <span class="text-[13px] text-ios-gray mt-0.5">kcal</span>
      </div>
    </div>

    <!-- Stats row -->
    <div class="flex gap-8 mt-3">
      <div class="flex flex-col items-center">
        <div class="flex items-center gap-1">
          <div class="w-2.5 h-2.5 rounded-full bg-ios-blue"/>
          <span class="text-[13px] font-semibold text-black">{{ goal.toLocaleString('vi') }}</span>
        </div>
        <span class="text-[11px] text-ios-gray mt-0.5">Mục tiêu</span>
      </div>
      <div class="w-px h-8 bg-ios-gray5 self-center"/>
      <div class="flex flex-col items-center">
        <div class="flex items-center gap-1">
          <div class="w-2.5 h-2.5 rounded-full bg-ios-green"/>
          <span class="text-[13px] font-semibold text-black">{{ consumed.toLocaleString('vi') }}</span>
        </div>
        <span class="text-[11px] text-ios-gray mt-0.5">Đã ăn</span>
      </div>
      <div class="w-px h-8 bg-ios-gray5 self-center"/>
      <div class="flex flex-col items-center">
        <div class="flex items-center gap-1">
          <div class="w-2.5 h-2.5 rounded-full bg-ios-orange"/>
          <span class="text-[13px] font-semibold text-black">{{ burned.toLocaleString('vi') }}</span>
        </div>
        <span class="text-[11px] text-ios-gray mt-0.5">Tập luyện</span>
      </div>
    </div>
  </div>
</template>
