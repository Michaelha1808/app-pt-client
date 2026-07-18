import type { FoodAnalysisResult } from '@/types/food'
import type { HistoryStats, TimelineResult, TodayStats } from '@/types/meal'
import { type MealStreakResult, useStreak } from '@/composables/useStreak'

export function useMealLog() {
  const todayStats   = ref<TodayStats | null>(null)
  const historyStats = ref<HistoryStats | null>(null)
  const timeline     = ref<TimelineResult | null>(null)
  const loading      = ref(false)
  const { onMealLogged } = useStreak()

  async function fetchTodayStats(): Promise<void> {
    loading.value = true
    try {
      todayStats.value = await apiFetch<TodayStats>('/food/today')
    } catch {
      todayStats.value = { total_calories: 0, total_protein: 0, total_carbs: 0, total_fat: 0, calories_burned: 0, meals: [] }
    } finally {
      loading.value = false
    }
  }

  async function fetchHistory(date?: string): Promise<void> {
    loading.value = true
    try {
      const query = date ? `?date=${date}` : ''
      historyStats.value = await apiFetch<HistoryStats>(`/food/history${query}`)
    } catch {
      historyStats.value = null
    } finally {
      loading.value = false
    }
  }

  async function fetchTimeline(from?: string, to?: string): Promise<void> {
    loading.value = true
    try {
      const params = new URLSearchParams()
      if (from) params.set('from', from)
      if (to)   params.set('to', to)
      const qs = params.toString()
      timeline.value = await apiFetch<TimelineResult>(`/food/timeline${qs ? `?${qs}` : ''}`)
    } catch {
      timeline.value = null
    } finally {
      loading.value = false
    }
  }

  async function logMeal(result: FoodAnalysisResult, image?: string | null, aiAdvice?: string | null): Promise<boolean> {
    try {
      const res = await apiFetch<{ id: number; streak: MealStreakResult }>('/food/log', {
        method: 'POST',
        body: {
          food_name: result.food_name,
          serving:   result.serving,
          calories:  result.calories,
          protein:   result.protein,
          carbs:     result.carbs,
          fat:       result.fat,
          sodium:    result.sodium,
          ai_advice: aiAdvice ?? null,
          image:     image ?? null,
        },
      })
      if (res.streak) onMealLogged(res.streak)
      return true
    } catch {
      return false
    }
  }

  /** Log nhiều món (mâm/bàn tiệc) trong 1 request — streak cập nhật 1 lần. Trả số bản ghi đã lưu. */
  async function logMeals(results: FoodAnalysisResult[], image?: string | null): Promise<number> {
    if (results.length === 0) return 0
    try {
      const res = await apiFetch<{ ids: number[]; streak: MealStreakResult }>('/food/log-batch', {
        method: 'POST',
        body: {
          meals: results.map(r => ({
            food_name: r.food_name,
            serving:   r.serving,
            calories:  r.calories,
            protein:   r.protein,
            carbs:     r.carbs,
            fat:       r.fat,
            sodium:    r.sodium,
          })),
          image: image ?? null,
        },
      })
      if (res.streak) onMealLogged(res.streak)
      return res.ids?.length ?? 0
    } catch {
      return 0
    }
  }

  async function deleteLog(id: number): Promise<boolean> {
    try {
      await apiFetch(`/food/log/${id}`, { method: 'DELETE' })
      return true
    } catch {
      return false
    }
  }

  /** Ghi lại 1 bữa đã log trước đó thành bữa mới ngay bây giờ — không gọi AI. */
  async function relogMeal(id: number, serving?: string): Promise<boolean> {
    try {
      const res = await apiFetch<{ id: number; streak: MealStreakResult }>(`/food/relog/${id}`, {
        method: 'POST',
        body: serving !== undefined ? { serving } : {},
      })
      if (res.streak) onMealLogged(res.streak)
      return true
    } catch {
      return false
    }
  }

  return { todayStats, historyStats, timeline, loading, fetchTodayStats, fetchHistory, fetchTimeline, logMeal, logMeals, deleteLog, relogMeal }
}
