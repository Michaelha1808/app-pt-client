import { ref } from 'vue'
import { apiFetch } from '@/utils/api'
import { type MealStreakResult, useStreak } from '@/composables/useStreak'

export interface FrequentMealItem {
  food_name:    string
  serving:      string | null
  count:        number
  calories:     number
  protein:      number
  carbs:        number
  fat:          number
  sodium:       number
  image_url:    string | null
  last_log_id:  number
  is_favorite:  boolean
}

export interface FavoriteMeal {
  id:         number
  food_name:  string
  serving:    string | null
  calories:   number
  protein:    number
  carbs:      number
  fat:        number
  sodium:     number
  image_url:  string | null
}

export type MealSlot = 'morning' | 'noon' | 'evening'

/** Khung giờ hiện tại theo giờ local của máy — dùng để gợi ý món thường ăn. */
export function currentMealSlot(): MealSlot {
  const h = new Date().getHours()
  if (h >= 4 && h < 11) return 'morning'
  if (h >= 11 && h < 17) return 'noon'
  return 'evening'
}

const frequentItems = ref<FrequentMealItem[]>([])
const favorites      = ref<FavoriteMeal[]>([])
const loading        = ref(false)

export function useQuickLog() {
  const { onMealLogged } = useStreak()

  async function fetchFrequent(slot?: MealSlot, limit = 8): Promise<FrequentMealItem[]> {
    loading.value = true
    try {
      const params = new URLSearchParams()
      if (slot) params.set('slot', slot)
      params.set('limit', String(limit))
      const res = await apiFetch<{ items: FrequentMealItem[] }>(`/food/frequent?${params.toString()}`)
      frequentItems.value = res.items
      return res.items
    } catch {
      frequentItems.value = []
      return []
    } finally {
      loading.value = false
    }
  }

  async function fetchFavorites(): Promise<void> {
    try {
      const res = await apiFetch<{ items: FavoriteMeal[] }>('/food/favorites')
      favorites.value = res.items
    } catch {
      favorites.value = []
    }
  }

  async function addFavorite(payload: { meal_log_id: number } | {
    food_name: string; serving?: string | null; calories: number
    protein: number; carbs: number; fat: number; sodium: number
  }): Promise<boolean> {
    try {
      const res = await apiFetch<{ item: FavoriteMeal }>('/food/favorites', { method: 'POST', body: payload })
      favorites.value = [res.item, ...favorites.value]
      return true
    } catch {
      return false
    }
  }

  async function removeFavorite(id: number): Promise<void> {
    await apiFetch(`/food/favorites/${id}`, { method: 'DELETE' })
    favorites.value = favorites.value.filter(f => f.id !== id)
  }

  async function logFavorite(id: number): Promise<boolean> {
    try {
      const res = await apiFetch<{ id: number; streak: MealStreakResult }>(`/food/favorites/${id}/log`, { method: 'POST' })
      if (res.streak) onMealLogged(res.streak)
      return true
    } catch {
      return false
    }
  }

  return {
    frequentItems, favorites, loading,
    fetchFrequent, fetchFavorites, addFavorite, removeFavorite, logFavorite,
  }
}
