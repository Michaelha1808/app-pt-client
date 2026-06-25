<script setup lang="ts">
import { formatQty } from '@/utils/nutrition'

const props = withDefaults(defineProps<{
  modelValue: number
  step?: number
  min?: number
  max?: number
  unitLabel?: string
  disabled?: boolean
}>(), {
  step: 1,
  min: 1,
  max: 99,
  unitLabel: '',
  disabled: false,
})

const emit = defineEmits<{ 'update:modelValue': [number] }>()

// Tránh sai số dấu phẩy động (vd 0.1+0.2)
function round(v: number): number {
  return Math.round(v / props.step) * props.step
}

function dec() {
  if (props.disabled) return
  const next = round(props.modelValue - props.step)
  if (next >= props.min) emit('update:modelValue', next)
}

function inc() {
  if (props.disabled) return
  const next = round(props.modelValue + props.step)
  if (next <= props.max) emit('update:modelValue', next)
}
</script>

<template>
  <div class="flex items-center gap-2" :class="disabled ? 'opacity-40' : ''">
    <button
      type="button"
      class="w-8 h-8 rounded-full bg-ios-gray6 flex items-center justify-center ios-press disabled:opacity-50"
      :disabled="disabled || modelValue <= min"
      @click="dec"
    >
      <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#18A874"><path d="M19 13H5v-2h14v2z"/></svg>
    </button>

    <div class="min-w-[60px] text-center">
      <span class="text-[15px] font-semibold text-black tabular-nums">{{ formatQty(modelValue) }}</span>
      <span v-if="unitLabel" class="text-[12px] text-ios-gray ml-1">{{ unitLabel }}</span>
    </div>

    <button
      type="button"
      class="w-8 h-8 rounded-full bg-ios-gray6 flex items-center justify-center ios-press disabled:opacity-50"
      :disabled="disabled || modelValue >= max"
      @click="inc"
    >
      <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#18A874"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
    </button>
  </div>
</template>
