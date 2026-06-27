<script setup lang="ts">
import AppToast from '@/components/ui/AppToast.vue'
</script>

<template>
  <div class="h-dvh bg-white flex flex-col overflow-hidden">
    <div class="flex-none bg-white" style="height: env(safe-area-inset-top)" />
    <div class="flex-1 overflow-y-auto overscroll-contain">
      <slot />
    </div>
    <AppToast />
  </div>
</template>
