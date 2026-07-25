<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

const props = defineProps<{
  consumed: number
  goal: number
  burned?: number
  size?: number
  horizontal?: boolean
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
  if (progress.value >= 1) return '#c96a6a'      // exceeded – red
  if (progress.value >= 0.85) return '#FF9500'   // near limit – orange
  return 'url(#ringGradient)'                     // normal – matcha green
})

const ringSize = computed(() => props.size ?? (props.horizontal ? 140 : 220))

onMounted(() => {
  requestAnimationFrame(() => setTimeout(() => (animated.value = true), 100))
})
</script>

<template>
  <!-- ══════════ Bố cục NGANG: ring bên trái + stats xếp dọc bên phải ══════════ -->
  <div v-if="horizontal" class="flex items-center gap-4">
    <div class="relative flex-shrink-0" :style="`width: ${ringSize}px; height: ${ringSize}px`">
      <svg :width="ringSize" :height="ringSize" viewBox="0 0 220 220" class="rotate-[-90deg]">
        <defs>
          <linearGradient id="ringGradient" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%"   stop-color="#8bab77"/>
            <stop offset="100%" stop-color="#5e7a54"/>
          </linearGradient>
        </defs>
        <circle cx="110" cy="110" :r="radius" fill="none" stroke="#eef5e9" stroke-width="16" stroke-linecap="round"/>
        <circle
          cx="110" cy="110" :r="radius" fill="none"
          :stroke="ringColor" stroke-width="16" stroke-linecap="round"
          :stroke-dasharray="circumference" :stroke-dashoffset="offset"
          style="transition: stroke-dashoffset 1.2s cubic-bezier(0.16,1,0.3,1), stroke 0.4s ease"
        />
      </svg>
      <div class="absolute inset-0 flex flex-col items-center justify-center">
        <span class="text-[10px] font-medium text-ios-gray uppercase tracking-wider">Còn lại</span>
        <span
          class="font-display text-[30px] font-bold leading-none mt-0.5 tabular-nums"
          :class="progress >= 1 ? 'text-ios-red' : 'text-calor-deep'"
        >{{ remaining.toLocaleString('vi') }}</span>
        <span class="text-[11px] text-ios-gray mt-0.5">kcal</span>
      </div>
    </div>

    <!-- Stats xếp dọc: emoji · nhãn (trái) — số (phải), ngăn bằng đường kẻ -->
    <div class="flex-1 flex flex-col gap-2.5">
      <div class="flex items-center justify-between">
        <span class="text-[12px] text-ios-gray">🎯 Mục tiêu</span>
        <b class="font-display text-[15px] font-bold text-calor-deep tabular-nums">{{ goal.toLocaleString('vi') }}</b>
      </div>
      <div class="h-px bg-[#eef1e8]"/>
      <div class="flex items-center justify-between">
        <span class="text-[12px] text-ios-gray">🍽 Đã ăn</span>
        <b class="font-display text-[15px] font-bold text-calor-deep tabular-nums">{{ consumed.toLocaleString('vi') }}</b>
      </div>
      <div class="h-px bg-[#eef1e8]"/>
      <div class="flex items-center justify-between">
        <span class="text-[12px] text-ios-gray">🔥 Tập luyện</span>
        <b class="font-display text-[15px] font-bold text-calor-deep tabular-nums">{{ burned.toLocaleString('vi') }}</b>
      </div>
    </div>
  </div>

  <!-- ══════════ Bố cục DỌC (mặc định, giữ tương thích) ══════════ -->
  <div v-else class="flex flex-col items-center">
    <div class="relative" :style="`width: ${ringSize}px; height: ${ringSize}px`">
      <svg :width="ringSize" :height="ringSize" viewBox="0 0 220 220" class="rotate-[-90deg]">
        <defs>
          <linearGradient id="ringGradient" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%"   stop-color="#8bab77"/>
            <stop offset="100%" stop-color="#5e7a54"/>
          </linearGradient>
        </defs>
        <circle cx="110" cy="110" :r="radius" fill="none" stroke="#eef5e9" stroke-width="16" stroke-linecap="round"/>
        <circle
          cx="110" cy="110" :r="radius" fill="none"
          :stroke="ringColor" stroke-width="16" stroke-linecap="round"
          :stroke-dasharray="circumference" :stroke-dashoffset="offset"
          style="transition: stroke-dashoffset 1.2s cubic-bezier(0.16,1,0.3,1), stroke 0.4s ease"
        />
      </svg>
      <div class="absolute inset-0 flex flex-col items-center justify-center">
        <span class="text-[11px] font-medium text-ios-gray uppercase tracking-wider">Còn lại</span>
        <span
          class="font-display text-[46px] font-bold leading-none mt-0.5 tabular-nums"
          :class="progress >= 1 ? 'text-ios-red' : 'text-black'"
        >{{ remaining.toLocaleString('vi') }}</span>
        <span class="text-[13px] text-ios-gray mt-0.5">kcal</span>
      </div>
    </div>

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
