<script setup lang="ts">
const { date, time, ms, mounted } = useClock()

const parts = computed(() => {
  const [hours = '--', minutes = '--', seconds = '--'] = time.value.split(':')

  return { hours, minutes, seconds }
})
</script>

<template>
  <div v-if="!mounted" class="flex animate-pulse flex-col items-center gap-3">
    <div class="h-6 w-64 rounded bg-slate-700" />
    <div class="h-24 w-80 rounded bg-slate-700" />
  </div>

  <div v-else class="flex select-none flex-col items-center gap-3">
    <p class="text-sm font-medium tracking-widest text-slate-400 uppercase">
      {{ date }}
    </p>

    <div class="flex items-end gap-1 font-mono">
      <span class="text-8xl font-bold leading-none text-white tabular-nums">
        {{ parts.hours }}
      </span>
      <span class="mb-1 text-6xl font-bold leading-none text-blue-400">:</span>
      <span class="text-8xl font-bold leading-none text-white tabular-nums">
        {{ parts.minutes }}
      </span>
      <span class="mb-1 text-6xl font-bold leading-none text-blue-400">:</span>
      <span class="text-8xl font-bold leading-none text-white tabular-nums">
        {{ parts.seconds }}
      </span>
      <span class="mb-2 ml-1 text-2xl font-semibold leading-none text-slate-500">
        .{{ ms }}
      </span>
    </div>
  </div>
</template>
