<script setup lang="ts">
import { MILESTONE_META } from '@/composables/useStreak'

const props = defineProps<{
  days:         number
  earnedToken?: boolean
}>()

const emit = defineEmits<{ close: [] }>()

const meta = computed(() => MILESTONE_META[props.days] ?? { emoji: '🥑', name: `${props.days} ngày` })

// Auto-dismiss sau 4 giây
onMounted(() => setTimeout(() => emit('close'), 4000))

// Confetti particles
const particles = Array.from({ length: 20 }, (_, i) => ({
  id:    i,
  left:  `${Math.random() * 100}%`,
  delay: `${Math.random() * 0.8}s`,
  color: ['#34C759', '#007AFF', '#FF9500', '#FF2D55', '#AF52DE'][i % 5],
  size:  `${6 + Math.random() * 6}px`,
}))
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[100] flex items-center justify-center pointer-events-none">
      <!-- Confetti -->
      <div
        v-for="p in particles"
        :key="p.id"
        class="absolute top-0 rounded-sm animate-confetti"
        :style="{
          left:            p.left,
          width:           p.size,
          height:          p.size,
          background:      p.color,
          animationDelay:  p.delay,
        }"
      />

      <!-- Card -->
      <div
        class="pointer-events-auto bg-white rounded-[28px] px-8 py-8 mx-6 text-center shadow-2xl animate-pop"
      >
        <div class="text-[72px] leading-none mb-3">{{ meta.emoji }}</div>
        <h2 class="text-[22px] font-black text-black mb-1">{{ meta.name }}!</h2>
        <p class="text-[14px] text-ios-gray mb-4">
          Bạn đã log <strong class="text-calor-deep">{{ days }} ngày</strong> liên tiếp
        </p>

        <div
          v-if="earnedToken"
          class="bg-ios-blue/10 rounded-[14px] px-4 py-2.5 mb-5 text-[13px] font-semibold text-ios-blue"
        >
          + 1 Freeze Token nhận được ❄️
        </div>

        <button
          class="w-full py-3 bg-calor-green rounded-[16px] text-white font-semibold text-[16px] ios-press"
          @click="emit('close')"
        >
          Tuyệt vời! 🥑
        </button>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
@keyframes confetti-fall {
  0%   { transform: translateY(-20px) rotate(0deg); opacity: 1; }
  100% { transform: translateY(110vh) rotate(720deg); opacity: 0; }
}
.animate-confetti {
  animation: confetti-fall 2.5s ease-in forwards;
}
@keyframes pop {
  0%   { transform: scale(0.5); opacity: 0; }
  70%  { transform: scale(1.05); }
  100% { transform: scale(1); opacity: 1; }
}
.animate-pop {
  animation: pop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}
</style>
