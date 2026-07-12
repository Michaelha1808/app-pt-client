<script setup lang="ts">
import { ref, computed, onMounted, type Component } from 'vue'
import { useAdmin } from '@/composables/useAdmin'
import { useAuth } from '@/composables/useAuth'
import type { AdminStats } from '@/types/admin'
import TrendChart from '@/components/admin/TrendChart.vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Users, Activity, UserX, UtensilsCrossed, ScanLine, MessageSquare,
  BellRing, Flame, ArrowUp, TrendingUp, PieChart,
} from 'lucide-vue-next'

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

function fmt(n: number): string {
  return new Intl.NumberFormat('vi-VN').format(n)
}

// ── KPI tiles (kiểu Gentelella tile_stats_count) ──
interface KpiTile {
  label: string
  icon: Component
  value: number
  delta?: number       // biến động hôm nay (mũi tên xanh khi > 0)
  sub?: string
  warn?: boolean       // tô đỏ giá trị (vd tài khoản bị khoá)
}

const kpiTiles = computed<KpiTile[]>(() => {
  const k = stats.value?.kpi
  if (!k) return []
  return [
    { label: 'Tổng người dùng', icon: Users,           value: k.total_users,            delta: k.new_users_today },
    { label: 'Active 7 ngày',   icon: Activity,        value: k.active_users_7d,        sub: 'người dùng' },
    { label: 'Meal logs',       icon: UtensilsCrossed, value: k.total_meal_logs,        delta: k.meal_logs_today },
    { label: 'Streak đang chạy', icon: Flame,          value: k.active_streaks,         sub: 'người dùng' },
    { label: 'AI phân tích món', icon: ScanLine,       value: k.ai_food_analyses_today, sub: 'hôm nay' },
    { label: 'AI chat',         icon: MessageSquare,   value: k.ai_chat_messages_today, sub: 'hôm nay' },
    { label: 'Push đã gửi',     icon: BellRing,        value: k.push_sent_today,        sub: 'hôm nay' },
    { label: 'Bị khoá',         icon: UserX,           value: k.suspended_users,        sub: 'tài khoản', warn: true },
  ]
})

// ── Charts (small multiples, 1 series/panel — màu đã validate CVD) ──
const charts = computed(() => {
  const s = stats.value?.series
  if (!s) return []
  return [
    { title: 'Người dùng mới', data: s.new_users, color: '#18A874' },
    { title: 'Meal logs',      data: s.meal_logs, color: '#eb6834' },
    { title: 'Lượt gọi AI',    data: s.ai_calls,  color: '#4a3aa7' },
  ].map(c => ({ ...c, total: c.data.reduce((a, p) => a + p.count, 0) }))
})

// ── Breakdown (màu theo entity: brand provider; bộ giới tính đã validate) ──
const providerMeta: Record<string, { label: string; color: string }> = {
  email:    { label: 'Email',    color: '#18A874' },
  google:   { label: 'Google',   color: '#EA4335' },
  facebook: { label: 'Facebook', color: '#1877F2' },
  apple:    { label: 'Apple',    color: '#111827' },
}
const genderMeta: Record<string, { label: string; color: string }> = {
  male:    { label: 'Nam',     color: '#2a78d6' },
  female:  { label: 'Nữ',      color: '#e87ba4' },
  other:   { label: 'Khác',    color: '#4a3aa7' },
  unknown: { label: 'Chưa rõ', color: '#8E8E93' },
}

const providerBreakdown = computed(() => Object.entries(stats.value?.breakdown.by_provider ?? {}))
const genderBreakdown = computed(() => Object.entries(stats.value?.breakdown.by_gender ?? {}))

function sumValues(entries: [string, number][]): number {
  return entries.reduce((a, [, v]) => a + v, 0) || 1
}

const RANGE_LABELS = { '7d': '7 ngày', '30d': '30 ngày', '90d': '90 ngày' } as const
</script>

<template>
  <div>
    <!-- Page header -->
    <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
      <div>
        <h1 class="text-xl font-bold">Tổng quan</h1>
        <p class="text-sm text-muted-foreground mt-0.5">Sức khoẻ hệ thống trong {{ RANGE_LABELS[range] }} gần nhất.</p>
      </div>
      <!-- Bộ lọc thời gian: 1 hàng, scope toàn bộ chart bên dưới -->
      <div class="inline-flex bg-background rounded-lg border p-0.5 gap-0.5">
        <Button
          v-for="r in (['7d','30d','90d'] as const)" :key="r"
          :variant="range === r ? 'default' : 'ghost'" size="sm"
          @click="setRange(r)"
        >{{ RANGE_LABELS[r] }}</Button>
      </div>
    </div>

    <!-- Error -->
    <div v-if="error" class="mb-4 p-4 bg-destructive/10 text-destructive rounded-lg flex items-center justify-between">
      <span>{{ error }}</span>
      <Button variant="link" class="text-destructive underline h-auto p-0" @click="load">Thử lại</Button>
    </div>

    <!-- Loading skeleton -->
    <div v-if="loading" class="space-y-4">
      <Skeleton class="h-28 rounded-xl" />
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <Skeleton v-for="i in 3" :key="i" class="h-56 rounded-xl" />
      </div>
    </div>

    <template v-else-if="stats">
      <!-- ══ KPI tile row (ô ngăn bằng hairline, kiểu Gentelella) ══ -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-px bg-border rounded-xl border overflow-hidden shadow-xs mb-5">
        <div v-for="t in kpiTiles" :key="t.label" class="bg-card px-4 py-3.5">
          <div class="flex items-center gap-1.5 text-muted-foreground">
            <component :is="t.icon" class="w-3.5 h-3.5" />
            <span class="text-xs font-medium truncate">{{ t.label }}</span>
          </div>
          <div class="mt-1.5 text-[26px] leading-8 font-semibold" :class="t.warn && t.value > 0 ? 'text-destructive' : 'text-foreground'">
            {{ fmt(t.value) }}
          </div>
          <div class="mt-0.5 text-xs flex items-center gap-1">
            <template v-if="t.delta !== undefined">
              <ArrowUp v-if="t.delta > 0" class="w-3 h-3 text-emerald-700" />
              <span :class="t.delta > 0 ? 'text-emerald-700 font-medium' : 'text-muted-foreground/80'">
                {{ t.delta > 0 ? `+${fmt(t.delta)}` : '0' }} hôm nay
              </span>
            </template>
            <span v-else class="text-muted-foreground/80">{{ t.sub }}</span>
          </div>
        </div>
      </div>

      <!-- ══ Trend charts (small multiples) ══ -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-5">
        <Card v-for="ch in charts" :key="ch.title" class="py-0 gap-0">
          <CardHeader class="px-4 py-3 border-b flex-row items-center justify-between space-y-0">
            <CardTitle class="text-sm flex items-center gap-2">
              <TrendingUp class="w-4 h-4" :style="{ color: ch.color }" />
              {{ ch.title }}
            </CardTitle>
            <span class="text-xs text-muted-foreground tabular-nums">Tổng: {{ fmt(ch.total) }}</span>
          </CardHeader>
          <CardContent class="px-4 pt-4 pb-3">
            <TrendChart :data="ch.data" :color="ch.color" />
          </CardContent>
        </Card>
      </div>

      <!-- ══ Breakdown ══ -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <Card class="py-0 gap-0">
          <CardHeader class="px-4 py-3 border-b flex-row items-center justify-between space-y-0">
            <CardTitle class="text-sm flex items-center gap-2"><PieChart class="w-4 h-4 text-muted-foreground" /> Theo nguồn đăng nhập</CardTitle>
            <span class="text-xs text-muted-foreground tabular-nums">{{ fmt(sumValues(providerBreakdown)) }} tài khoản</span>
          </CardHeader>
          <CardContent class="px-4 py-4 space-y-3">
            <div v-for="[name, val] in providerBreakdown" :key="name">
              <div class="flex items-center justify-between text-xs mb-1">
                <span class="flex items-center gap-1.5 text-foreground/80 font-medium">
                  <span class="w-2 h-2 rounded-full inline-block" :style="{ background: providerMeta[name]?.color || '#8E8E93' }" />
                  {{ providerMeta[name]?.label || name }}
                </span>
                <span class="text-muted-foreground tabular-nums">
                  {{ fmt(val) }} · {{ Math.round(val / sumValues(providerBreakdown) * 100) }}%
                </span>
              </div>
              <div class="h-2 bg-muted rounded-full overflow-hidden">
                <div class="h-full rounded-full"
                     :style="{ width: (val / sumValues(providerBreakdown) * 100) + '%', background: providerMeta[name]?.color || '#8E8E93' }" />
              </div>
            </div>
            <p v-if="!providerBreakdown.length" class="text-sm text-muted-foreground text-center py-4">Chưa có dữ liệu</p>
          </CardContent>
        </Card>

        <Card class="py-0 gap-0">
          <CardHeader class="px-4 py-3 border-b flex-row items-center justify-between space-y-0">
            <CardTitle class="text-sm flex items-center gap-2"><PieChart class="w-4 h-4 text-muted-foreground" /> Theo giới tính</CardTitle>
            <span class="text-xs text-muted-foreground tabular-nums">{{ fmt(sumValues(genderBreakdown)) }} tài khoản</span>
          </CardHeader>
          <CardContent class="px-4 py-4 space-y-3">
            <div v-for="[name, val] in genderBreakdown" :key="name">
              <div class="flex items-center justify-between text-xs mb-1">
                <span class="flex items-center gap-1.5 text-foreground/80 font-medium">
                  <span class="w-2 h-2 rounded-full inline-block" :style="{ background: genderMeta[name]?.color || '#8E8E93' }" />
                  {{ genderMeta[name]?.label || name }}
                </span>
                <span class="text-muted-foreground tabular-nums">
                  {{ fmt(val) }} · {{ Math.round(val / sumValues(genderBreakdown) * 100) }}%
                </span>
              </div>
              <div class="h-2 bg-muted rounded-full overflow-hidden">
                <div class="h-full rounded-full"
                     :style="{ width: (val / sumValues(genderBreakdown) * 100) + '%', background: genderMeta[name]?.color || '#8E8E93' }" />
              </div>
            </div>
            <p v-if="!genderBreakdown.length" class="text-sm text-muted-foreground text-center py-4">Chưa có dữ liệu</p>
          </CardContent>
        </Card>
      </div>
    </template>
  </div>
</template>
