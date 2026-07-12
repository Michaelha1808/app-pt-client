<script setup lang="ts">
import { ref, computed } from 'vue'
import type { SeriesPoint } from '@/types/admin'

/**
 * Line chart 1 series cho dashboard admin: đường 2px + area wash 10%,
 * gridline hairline, y-tick số tròn, chấm cuối có ring trắng + nhãn giá trị,
 * hover crosshair + tooltip (điểm gần nhất, hit target = cả vùng plot).
 */
const props = defineProps<{
  data: SeriesPoint[]
  color: string
}>()

const plotEl = ref<HTMLDivElement | null>(null)
const hoverIdx = ref<number | null>(null)

function fmt(n: number): string {
  return new Intl.NumberFormat('vi-VN').format(n)
}

/** Trần trục y làm tròn đẹp (1/2/5 × 10^n) để tick không lẻ. */
const axisMax = computed(() => {
  const m = Math.max(1, ...props.data.map(p => p.count))
  const pow = 10 ** Math.floor(Math.log10(m))
  const frac = m / pow
  const nice = frac <= 1 ? 1 : frac <= 2 ? 2 : frac <= 5 ? 5 : 10
  return nice * pow
})

/** Toạ độ % (0–100) trong vùng plot. */
const points = computed(() =>
  props.data.map((p, i) => ({
    x: props.data.length > 1 ? (i / (props.data.length - 1)) * 100 : 50,
    y: 100 - (p.count / axisMax.value) * 100,
    ...p,
  })),
)

const linePath = computed(() =>
  points.value.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x.toFixed(2)},${p.y.toFixed(2)}`).join(' '))

const areaPath = computed(() =>
  points.value.length ? `${linePath.value} L100,100 L0,100 Z` : '')

const last = computed(() => points.value[points.value.length - 1] ?? null)
const hovered = computed(() => (hoverIdx.value !== null ? points.value[hoverIdx.value] ?? null : null))

function onMove(e: MouseEvent) {
  const el = plotEl.value
  if (!el || !points.value.length) return
  const rect = el.getBoundingClientRect()
  const pct = ((e.clientX - rect.left) / rect.width) * 100
  let best = 0
  for (let i = 1; i < points.value.length; i++) {
    if (Math.abs(points.value[i].x - pct) < Math.abs(points.value[best].x - pct)) best = i
  }
  hoverIdx.value = best
}

function dd(date: string): string {
  return date.slice(5).split('-').reverse().join('/')
}
</script>

<template>
  <div class="select-none">
    <div class="flex">
      <!-- Y ticks -->
      <div class="w-10 flex flex-col justify-between text-right pr-2 py-0 h-28 flex-none">
        <span class="text-[10px] text-muted-foreground/80 leading-none tabular-nums -mt-1">{{ fmt(axisMax) }}</span>
        <span class="text-[10px] text-muted-foreground/80 leading-none tabular-nums">{{ axisMax >= 2 ? fmt(axisMax / 2) : '' }}</span>
        <span class="text-[10px] text-muted-foreground/80 leading-none tabular-nums -mb-1">0</span>
      </div>

      <!-- Plot -->
      <div
        ref="plotEl"
        class="relative flex-1 h-28 cursor-crosshair"
        @mousemove="onMove"
        @mouseleave="hoverIdx = null"
      >
        <!-- Gridlines (hairline, solid, recessive) -->
        <div class="absolute inset-x-0 top-0 h-px bg-border/70" />
        <div class="absolute inset-x-0 top-1/2 h-px bg-border/70" />
        <div class="absolute inset-x-0 bottom-0 h-px bg-border" />

        <svg class="absolute inset-0 w-full h-full overflow-visible" viewBox="0 0 100 100" preserveAspectRatio="none">
          <path :d="areaPath" :fill="color" fill-opacity="0.1" />
          <path
            :d="linePath" fill="none" :stroke="color" stroke-width="2"
            vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"
          />
        </svg>

        <!-- Crosshair hover -->
        <template v-if="hovered">
          <div class="absolute top-0 bottom-0 w-px bg-foreground/20" :style="{ left: hovered.x + '%' }" />
          <div
            class="absolute w-2 h-2 rounded-full ring-2 ring-white -translate-x-1/2 -translate-y-1/2"
            :style="{ left: hovered.x + '%', top: hovered.y + '%', background: color }"
          />
          <div
            class="absolute z-10 pointer-events-none rounded-md border bg-popover px-2 py-1 shadow-md whitespace-nowrap"
            :style="{
              left: hovered.x + '%',
              top: Math.max(hovered.y - 8, 0) + '%',
              transform: hovered.x > 60 ? 'translate(calc(-100% - 8px), -100%)' : 'translate(8px, -100%)',
            }"
          >
            <div class="text-[10px] text-muted-foreground leading-tight">{{ dd(hovered.date) }}</div>
            <div class="text-xs font-semibold leading-tight tabular-nums">{{ fmt(hovered.count) }}</div>
          </div>
        </template>

        <!-- Chấm cuối + nhãn giá trị (mực chữ, không mang màu series) -->
        <template v-if="last && hoverIdx === null">
          <div
            class="absolute w-2 h-2 rounded-full ring-2 ring-white -translate-x-1/2 -translate-y-1/2"
            :style="{ left: last.x + '%', top: last.y + '%', background: color }"
          />
          <span
            class="absolute text-[10px] font-semibold text-foreground/80"
            :style="{
              left: last.x + '%',
              top: last.y + '%',
              transform: last.y < 18 ? 'translate(calc(-100% - 6px), 4px)' : 'translate(calc(-100% + 4px), calc(-100% - 5px))',
            }"
          >{{ fmt(last.count) }}</span>
        </template>
      </div>
    </div>

    <!-- X labels -->
    <div class="flex justify-between text-[10px] text-muted-foreground/80 mt-1.5 pl-10">
      <span>{{ data[0] ? dd(data[0].date) : '' }}</span>
      <span>{{ data.length ? dd(data[data.length - 1].date) : '' }}</span>
    </div>
  </div>
</template>
