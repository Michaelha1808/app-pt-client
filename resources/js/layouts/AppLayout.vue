<script setup lang="ts">
import { computed } from 'vue'
import IosBottomNav from '@/components/ios/BottomNav.vue'
import CaloeyeFloatingChar from '@/components/caloeye/FloatingChar.vue'
import AppToast from '@/components/ui/AppToast.vue'
import { useUiSettings } from '@/composables/useUiSettings'

const { fontZoom } = useUiSettings()

// Cỡ chữ: chỉ áp zoom khi khác 1 (Nhỏ/Lớn). Ở mức Trung bình → không style gì, layout y như gốc.
// width bù lại để nội dung vẫn vừa khung 430px (zoom phóng cả chiều rộng).
const zoomStyle = computed(() =>
  fontZoom.value === 1
    ? undefined
    : { zoom: fontZoom.value, width: `calc(100% / ${fontZoom.value})` },
)
</script>

<template>
  <div class="h-dvh flex flex-col bg-[#F2F8F5] overflow-hidden">
    <!-- Safe area spacer (real device status bar) -->
    <div class="flex-none bg-[#F2F8F5]" style="height: env(safe-area-inset-top)" />
    <div class="flex-1 overflow-y-auto overscroll-contain">
      <div :style="zoomStyle">
        <slot />
      </div>
    </div>
    <IosBottomNav />
    <CaloeyeFloatingChar />
    <AppToast />
  </div>
</template>
