<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useAdmin } from '@/composables/useAdmin'
import { useAuth } from '@/composables/useAuth'
import type { AdminStats, SeriesPoint } from '@/types/admin'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'

const { fetchStats } = useAdmin()
const { extractError } = useAuth()

const stats = ref<AdminStats | null>(null)
const loading = ref(true)
const error = ref('')
const range = ref<'7d' | '30d' | '90d'>('30d')

async function load() {
  loading.value = true; error.value = ''
  try {
    stats.value = await fetchStats(range.value)
  } catch (e) {
    error.value = extractError(e) || 'Không tải được dữ liệu'
  } finally {
    loading.value = false
  }
}

function setRange(r: '7d' | '30d' | '90d') {
  if (range.value === r) return
  range.value = r
  load()
}

onMounted(load)

const kpiCards = computed(() => {
  const k = stats.value?.kpi
  if (!k) return []
  return [
    { label: 'Tổng người dùng', value: k.total_users, sub: `+${k.new_users_today} hôm nay`, color: 'text-calor-green' },
    { label: 'Active 7 ngày', value: k.active_users_7d, sub: `${k.suspended_users} bị khoá`, color: 'text-blue-600' },
    { label: 'Meal logs', value: k.total_meal_logs, sub: `+${k.meal_logs_today} hôm nay`, color: 'text-orange-500' },
    { label: 'AI phân tích món', value: k.ai_food_analyses_today, sub: 'hôm nay', color: 'text-purple-600' },
    { label: 'AI chat', value: k.ai_chat_messages_today, sub: 'hôm nay', color: 'text-pink-600' },
    { label: 'Push đã gửi', value: k.push_sent_today, sub: 'hôm nay', color: 'text-teal-600' },
    { label: 'Streak đang chạy', value: k.active_streaks, sub: 'người dùng', color: 'text-amber-500' },
  ]
})

function fmt(n: number): string {
  return new Intl.NumberFormat('vi-VN').format(n)
}

// ── SVG line chart helpers ──
function buildPath(points: SeriesPoint[], w = 600, h = 120, pad = 4): string {
  if (!points.length) return ''
  const max = Math.max(1, ...points.map(p => p.count))
  const stepX = (w - pad * 2) / Math.max(1, points.length - 1)
  return points.map((p, i) => {
    const x = pad + i * stepX
    const y = h - pad - (p.count / max) * (h - pad * 2)
    return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`
  }).join(' ')
}

const charts = computed(() => {
  const s = stats.value?.series
  if (!s) return []
  return [
    { title: 'Người dùng mới', data: s.new_users, stroke: '#18A874' },
    { title: 'Meal logs', data: s.meal_logs, stroke: '#FF9500' },
    { title: 'Lượt gọi AI', data: s.ai_calls, stroke: '#AF52DE' },
  ]
})

const providerBreakdown = computed(() => Object.entries(stats.value?.breakdown.by_provider ?? {}))
const genderBreakdown = computed(() => Object.entries(stats.value?.breakdown.by_gender ?? {}))

function sumValues(entries: [string, number][]): number {
  return entries.reduce((a, [, v]) => a + v, 0) || 1
}

const providerColors: Record<string, string> = {
  email: '#18A874', google: '#EA4335', facebook: '#1877F2', apple: '#111',
}
const genderColors: Record<string, string> = {
  male: '#32ADE6', female: '#FF2D55', other: '#AF52DE', unknown: '#8E8E93',
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
      <h1 class="text-xl font-bold">Tổng quan</h1>
      <div class="inline-flex bg-background rounded-lg border p-0.5 gap-0.5">
        <Button
          v-for="r in (['7d','30d','90d'] as const)" :key="r"
          :variant="range === r ? 'default' : 'ghost'" size="sm"
          @click="setRange(r)"
        >{{ r === '7d' ? '7 ngày' : r === '30d' ? '30 ngày' : '90 ngày' }}</Button>
      </div>
    </div>

    <!-- Error -->
    <div v-if="error" class="mb-4 p-4 bg-destructive/10 text-destructive rounded-lg flex items-center justify-between">
      <span>{{ error }}</span>
      <Button variant="link" class="text-destructive underline h-auto p-0" @click="load">Thử lại</Button>
    </div>

    <!-- Loading skeleton -->
    <div v-if="loading" class="grid grid-cols-2 md:grid-cols-4 gap-4">
      <Skeleton v-for="i in 8" :key="i" class="h-24 rounded-xl" />
    </div>

    <template v-else-if="stats">
      <!-- KPI cards -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <Card v-for="c in kpiCards" :key="c.label" class="py-4 gap-1">
          <CardContent class="px-4">
            <div class="text-xs text-muted-foreground font-medium">{{ c.label }}</div>
            <div class="mt-1 text-2xl font-bold" :class="c.color">{{ fmt(c.value) }}</div>
            <div class="text-xs text-muted-foreground/70 mt-0.5">{{ c.sub }}</div>
          </CardContent>
        </Card>
      </div>

      <!-- Charts -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <Card v-for="ch in charts" :key="ch.title" class="py-4 gap-2">
          <CardHeader class="px-4">
            <CardTitle class="text-sm">{{ ch.title }}</CardTitle>
          </CardHeader>
          <CardContent class="px-4">
            <svg viewBox="0 0 600 120" class="w-full h-28" preserveAspectRatio="none">
              <path :d="buildPath(ch.data)" fill="none" :stroke="ch.stroke" stroke-width="3"
                    stroke-linejoin="round" stroke-linecap="round" />
            </svg>
            <div class="flex justify-between text-[10px] text-muted-foreground mt-1">
              <span>{{ ch.data[0]?.date.slice(5) }}</span>
              <span>{{ ch.data[ch.data.length - 1]?.date.slice(5) }}</span>
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Breakdown -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <Card class="py-4 gap-2">
          <CardHeader class="px-4">
            <CardTitle class="text-sm">Theo nguồn đăng nhập</CardTitle>
          </CardHeader>
          <CardContent class="px-4 space-y-2">
            <div v-for="[name, val] in providerBreakdown" :key="name">
              <div class="flex justify-between text-xs mb-1">
                <span class="capitalize text-muted-foreground">{{ name }}</span>
                <span class="text-muted-foreground/70">{{ fmt(val) }}</span>
              </div>
              <div class="h-2 bg-muted rounded-full overflow-hidden">
                <div class="h-full rounded-full"
                     :style="{ width: (val / sumValues(providerBreakdown) * 100) + '%', background: providerColors[name] || '#8E8E93' }" />
              </div>
            </div>
          </CardContent>
        </Card>
        <Card class="py-4 gap-2">
          <CardHeader class="px-4">
            <CardTitle class="text-sm">Theo giới tính</CardTitle>
          </CardHeader>
          <CardContent class="px-4 space-y-2">
            <div v-for="[name, val] in genderBreakdown" :key="name">
              <div class="flex justify-between text-xs mb-1">
                <span class="capitalize text-muted-foreground">{{ name === 'male' ? 'Nam' : name === 'female' ? 'Nữ' : name === 'other' ? 'Khác' : 'Chưa rõ' }}</span>
                <span class="text-muted-foreground/70">{{ fmt(val) }}</span>
              </div>
              <div class="h-2 bg-muted rounded-full overflow-hidden">
                <div class="h-full rounded-full"
                     :style="{ width: (val / sumValues(genderBreakdown) * 100) + '%', background: genderColors[name] || '#8E8E93' }" />
              </div>
            </div>
          </CardContent>
        </Card>
      </div>
    </template>
  </div>
</template>
