<script setup lang="ts">
import type { HealthResponse } from '@/types/api'

type Status = 'loading' | 'ok' | 'error'

const status = ref<Status>('loading')
const info = ref<HealthResponse | null>(null)
const error = ref('')

const dotClass = computed(() => {
  const classes: Record<Status, string> = {
    loading: 'bg-yellow-400 animate-pulse',
    ok: 'bg-green-400',
    error: 'bg-red-400',
  }

  return classes[status.value]
})

const label = computed(() => {
  if (status.value === 'loading') {
    return 'Connecting to API…'
  }

  if (status.value === 'ok') {
    return `API online — v${info.value?.version ?? ''}`
  }

  return `API unreachable — ${error.value}`
})

onMounted(async () => {
  try {
    info.value = await apiFetch<HealthResponse>('/health')
    status.value = 'ok'
  } catch (err: unknown) {
    error.value = err instanceof Error ? err.message : 'Unknown error'
    status.value = 'error'
  }
})
</script>

<template>
  <div class="flex items-center gap-2 rounded-full border border-slate-700 bg-slate-800/60 px-4 py-2 text-sm">
    <span class="h-2.5 w-2.5 rounded-full" :class="dotClass" />
    <span class="text-slate-300">{{ label }}</span>
  </div>
</template>
