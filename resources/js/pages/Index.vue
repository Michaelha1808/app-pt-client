<script setup lang="ts">
import CaloeyeCharacter from '@/components/caloeye/Character.vue'

const router = useRouter()
onMounted(() => {
  setTimeout(() => router.push('/auth/login'), 2600)
})
</script>

<template>
  <div
    class="h-dvh flex flex-col items-center justify-center select-none overflow-hidden"
    style="background: linear-gradient(160deg, #0F6E56 0%, #18A874 50%, #9FE1CB 100%)"
  >
    <!-- Background leaf decoration -->
    <div class="absolute inset-0 pointer-events-none overflow-hidden">
      <svg viewBox="0 0 400 800" class="absolute -right-24 top-10 w-64 opacity-10" fill="white">
        <path d="M200,20 C200,20 320,80 320,300 C320,420 270,490 200,510 C130,490 80,420 80,300 C80,80 200,20 200,20 Z"/>
      </svg>
      <svg viewBox="0 0 400 800" class="absolute -left-32 bottom-20 w-56 opacity-8" fill="white">
        <path d="M200,20 C200,20 320,80 320,300 C320,420 270,490 200,510 C130,490 80,420 80,300 C80,80 200,20 200,20 Z"/>
      </svg>
    </div>

    <!-- Character (large, centered) -->
    <div class="animate-scaleIn mb-4" style="opacity:0">
      <CaloeyeCharacter mood="wave" :size="130" />
    </div>

    <!-- Logo wordmark -->
    <div class="animate-fadeInUp delay-2 flex flex-col items-center gap-1" style="opacity:0">
      <div class="flex items-baseline gap-1">
        <span class="text-[36px] font-bold text-white tracking-tight">Calor</span>
        <span class="text-[36px] font-bold text-[#C8F0E2] tracking-tight">Eye</span>
      </div>
      <p class="text-[14px] text-white/70 text-center px-8 leading-snug">
        Nhận diện tức thì, sống khỏe mỗi ngày
      </p>
      <p class="text-[11px] text-white/45 tracking-[0.3em] mt-1 uppercase">
        Food · AI · Health
      </p>
    </div>

    <!-- Loading dots -->
    <div class="absolute bottom-16 flex gap-2 animate-fadeIn delay-5" style="opacity:0">
      <div class="w-1.5 h-1.5 rounded-full bg-white/50 animate-breath" style="animation-delay:0s"/>
      <div class="w-1.5 h-1.5 rounded-full bg-white/50 animate-breath" style="animation-delay:0.35s"/>
      <div class="w-1.5 h-1.5 rounded-full bg-white/50 animate-breath" style="animation-delay:0.7s"/>
    </div>
  </div>
</template>
