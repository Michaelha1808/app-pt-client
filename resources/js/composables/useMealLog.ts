import type { FoodAnalysisResult } from '@/types/food'
import type { HistoryStats, TodayStats } from '@/types/meal'
import { type MealStreakResult, useStreak } from '@/composables/useStreak'

export function useMealLog() {
  const todayStats   = ref<TodayStats | null>(null)
  const historyStats = ref<HistoryStats | null>(null)
  const loading      = ref(false)
  const { onMealLogged } = useStreak()

  async function fetchTodayStats(): Promise<void> {
    loading.value = true
    try {
      todayStats.value = await apiFetch<TodayStats>('/food/today')
    } catch {
      todayStats.value = { total_calories: 0, total_protein: 0, total_carbs: 0, total_fat: 0, meals: [] }
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

  async function logMeal(result: FoodAnalysisResult): Promise<boolean> {
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
        },
      })
      if (res.streak) onMealLogged(res.streak)
      return true
    } catch {
      return false
    }
  }

  /** Log nhiều món (mâm/bàn tiệc) trong 1 request — streak cập nhật 1 lần. Trả số bản ghi đã lưu. */
  async function logMeals(results: FoodAnalysisResult[]): Promise<number> {
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

  return { todayStats, historyStats, loading, fetchTodayStats, fetchHistory, logMeal, logMeals, deleteLog }
}
