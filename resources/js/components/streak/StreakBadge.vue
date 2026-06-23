<script setup lang="ts">
defineProps<{ count: number; atRisk?: boolean }>()
defineEmits<{ click: [] }>()
</script>

<template>
  <button
    v-if="count > 0"
    class="flex items-center gap-1 px-2.5 py-1.5 rounded-full ios-press transition-all"
    :class="atRisk
      ? 'bg-ios-yellow/20 animate-pulse-slow'
      : 'bg-calor-light'"
    @click="$emit('click')"
  >
    <span class="text-[16px] leading-none">🥑</span>
    <span
      class="text-[13px] font-bold leading-none"
      :class="atRisk ? 'text-ios-orange' : 'text-calor-deep'"
    >{{ count }}</span>
  </button>
</template>
