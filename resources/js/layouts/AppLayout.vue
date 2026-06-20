<script setup lang="ts">
import IosBottomNav from '@/components/ios/BottomNav.vue'
import CaloeyeFloatingChar from '@/components/caloeye/FloatingChar.vue'
import AppToast from '@/components/ui/AppToast.vue'
</script>

<template>
  <div class="h-svh flex flex-col bg-[#F2F8F5] overflow-hidden">
    <!-- Safe area spacer (real device status bar) -->
    <div class="flex-none bg-[#F2F8F5]" style="height: env(safe-area-inset-top)" />
    <div class="flex-1 overflow-y-auto overscroll-contain">
      <slot />
    </div>
    <IosBottomNav />
    <CaloeyeFloatingChar />
    <AppToast />
  </div>
</template>
