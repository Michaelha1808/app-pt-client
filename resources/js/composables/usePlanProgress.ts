import { ref } from 'vue'
import { apiFetch } from '@/utils/api'

export interface ProgressMeal {
  slot: string
  name: string
  calories: number | null
  done: boolean
}

export interface ProgressWorkout {
  name: string
  type: string | null
  duration_min: number | null
  done: boolean
}

export interface DayProgress {
  has_plan: boolean
  meals: ProgressMeal[]
  workout: ProgressWorkout | null
  done: number
  total: number
  percent: number
}

export interface WeekDay {
  date: string
  label: string
  /** null = ngày chưa tới (không vẽ cột, không tính vào % tuần) */
  percent: number | null
  done?: number
  total?: number
  is_future: boolean
  is_today: boolean
}

export interface PlanProgress {
  today: DayProgress
  week: { percent: number; done: number; total: number; days: WeekDay[] }
  encouragement: { title: string; message: string; emoji: string }
}

export function usePlanProgress() {
  const progress = ref<PlanProgress | null>(null)
  const loading  = ref(false)
  const error    = ref<string | null>(null)

  async function fetchProgress(): Promise<void> {
    loading.value = true
    error.value   = null
    try {
      progress.value = await apiFetch<PlanProgress>('/plan/progress')
    } catch (e: any) {
      if (e?.message !== 'auth:session_expired') error.value = 'Không tải được tiến độ kế hoạch.'
    } finally {
      loading.value = false
    }
  }

  return { progress, loading, error, fetchProgress }
}
