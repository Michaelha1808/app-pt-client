import { apiFetch } from '@/utils/api'

export interface WaterLogEntry {
  id:        number
  amount_ml: number
  logged_at: string
}

export interface WaterToday {
  total_ml: number
  goal_ml:  number
  logs:     WaterLogEntry[]
}

export const WATER_GOAL_ML = 2000

// Singleton state — shared across all calls to useWater()
const waterToday  = ref<WaterToday | null>(null)
const loading     = ref(false)
const justReached = ref(false)

export function useWater() {
  async function fetchWaterToday(): Promise<void> {
    loading.value = true
    try {
      waterToday.value = await apiFetch<WaterToday>('/water/today')
    } catch {
      waterToday.value = { total_ml: 0, goal_ml: WATER_GOAL_ML, logs: [] }
    } finally {
      loading.value = false
    }
  }

  async function logWater(amount_ml: number): Promise<void> {
    const wasReached = (waterToday.value?.total_ml ?? 0) >= WATER_GOAL_ML

    const res = await apiFetch<{ id: number; total_ml: number; goal_ml: number; reached: boolean }>('/water/log', {
      method: 'POST',
      body:   { amount_ml },
    })

    if (waterToday.value) {
      waterToday.value.total_ml = res.total_ml
      waterToday.value.logs.push({
        id:        res.id,
        amount_ml,
        logged_at: new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }),
      })
    }

    if (!wasReached && res.reached) {
      justReached.value = true
      setTimeout(() => { justReached.value = false }, 3000)
    }
  }

  async function deleteWaterLog(id: number): Promise<void> {
    const res = await apiFetch<{ total_ml: number }>(`/water/log/${id}`, { method: 'DELETE' })
    if (waterToday.value) {
      waterToday.value.total_ml = res.total_ml
      waterToday.value.logs = waterToday.value.logs.filter(l => l.id !== id)
    }
  }

  const totalMl     = computed(() => waterToday.value?.total_ml ?? 0)
  const percentage  = computed(() => Math.min((totalMl.value / WATER_GOAL_ML) * 100, 100))
  const isCompleted = computed(() => totalMl.value >= WATER_GOAL_ML)
  const remaining   = computed(() => Math.max(WATER_GOAL_ML - totalMl.value, 0))

  return {
    waterToday, loading, justReached,
    totalMl, percentage, isCompleted, remaining,
    fetchWaterToday, logWater, deleteWaterLog,
  }
}
