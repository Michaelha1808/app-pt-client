import type { FoodAnalysisResult } from '@/types/food'
import type { HistoryStats, TodayStats } from '@/types/meal'

export function useMealLog() {
  const todayStats   = ref<TodayStats | null>(null)
  const historyStats = ref<HistoryStats | null>(null)
  const loading      = ref(false)

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
      await apiFetch('/food/log', {
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
      return true
    } catch {
      return false
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

  return { todayStats, historyStats, loading, fetchTodayStats, fetchHistory, logMeal, deleteLog }
}
