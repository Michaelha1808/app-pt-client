<script setup lang="ts">
import AppToast from '@/components/ui/AppToast.vue'
</script>

<template>
  <div class="min-h-svh bg-white flex flex-col overflow-y-auto">
    <div class="flex-none bg-white" style="height: env(safe-area-inset-top)" />
    <slot />
    <AppToast />
  </div>
</template>
