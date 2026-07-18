<script setup lang="ts">
import IosBottomNav from '@/components/ios/BottomNav.vue'
import CaloeyeFloatingChar from '@/components/caloeye/FloatingChar.vue'
import AppToast from '@/components/ui/AppToast.vue'
import { useUiSettings } from '@/composables/useUiSettings'

const { fontZoom } = useUiSettings()
</script>

<template>
  <div class="h-dvh flex flex-col bg-[#F2F8F5] overflow-hidden">
    <!-- Safe area spacer (real device status bar) -->
    <div class="flex-none bg-[#F2F8F5]" style="height: env(safe-area-inset-top)" />
    <div class="flex-1 overflow-y-auto overscroll-contain">
      <!-- Cỡ chữ toàn app: zoom nội dung; width bù lại để không tràn ngang -->
      <div class="app-zoom" :style="{ '--ui-zoom': fontZoom }">
        <slot />
      </div>
    </div>
    <IosBottomNav />
    <CaloeyeFloatingChar />
    <AppToast />
  </div>
</template>

<style scoped>
.app-zoom {
  zoom: var(--ui-zoom, 1);
  width: calc(100% / var(--ui-zoom, 1));
}
</style>
