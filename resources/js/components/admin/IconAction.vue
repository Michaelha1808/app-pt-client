<script setup lang="ts">
import { computed } from 'vue'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'

/**
 * Nút hành động icon-only trong bảng, kèm tooltip.
 * Tone màu theo chuẩn UX: view = xám, edit = xanh, delete = đỏ, warn = hổ phách.
 */
const props = withDefaults(defineProps<{
  label: string
  tone?: 'view' | 'edit' | 'delete' | 'warn' | 'success'
  disabled?: boolean
}>(), { tone: 'view' })

const emit = defineEmits<{ (e: 'click', ev: MouseEvent): void }>()

const toneClass = computed(() => ({
  view:    'text-muted-foreground hover:text-foreground hover:bg-accent',
  edit:    'text-blue-600 hover:text-blue-700 hover:bg-blue-500/10',
  delete:  'text-destructive hover:text-destructive hover:bg-destructive/10',
  warn:    'text-amber-600 hover:text-amber-700 hover:bg-amber-500/10',
  success: 'text-emerald-600 hover:text-emerald-700 hover:bg-emerald-500/10',
}[props.tone]))
</script>

<template>
  <Tooltip>
    <TooltipTrigger as-child>
      <button
        type="button"
        :disabled="disabled"
        :aria-label="label"
        :class="cn(
          'inline-flex h-8 w-8 items-center justify-center rounded-md transition-colors disabled:pointer-events-none disabled:opacity-40 [&_svg]:w-4 [&_svg]:h-4',
          toneClass,
        )"
        @click="emit('click', $event)"
      >
        <slot />
      </button>
    </TooltipTrigger>
    <TooltipContent>{{ label }}</TooltipContent>
  </Tooltip>
</template>
