<script setup lang="ts">
import type { Component } from 'vue'
import { Inbox } from 'lucide-vue-next'

/** Trạng thái rỗng dùng chung cho bảng/danh sách admin. */
withDefaults(defineProps<{
  icon?: Component
  title: string
  hint?: string
}>(), { icon: Inbox })
</script>

<template>
  <div class="flex flex-col items-center justify-center gap-2 py-12 px-6 text-center">
    <div class="w-12 h-12 rounded-full bg-muted flex items-center justify-center">
      <component :is="icon" class="w-6 h-6 text-muted-foreground/70" />
    </div>
    <p class="text-sm font-medium text-foreground/80">{{ title }}</p>
    <p v-if="hint" class="text-xs text-muted-foreground max-w-sm">{{ hint }}</p>
    <slot />
  </div>
</template>
