import { ref } from 'vue'
import { apiFetch } from '@/utils/api'
import { useAuth } from '@/composables/useAuth'

export interface WeightEntry {
  id:           number
  weight_kg:    number
  logged_date:  string
  note:         string | null
}

export interface WeightTrend {
  start_weight_kg:   number
  current_weight_kg: number
  delta_kg:          number
  avg_7d_kg:         number
  weekly_rate_kg:    number
}

export interface WeightBmi {
  value: number
  label: string
}

export interface GoalSuggestion {
  current_goal:   number
  suggested_goal: number
  reason:         string
}

export interface WeightHistory {
  range:            number
  entries:          WeightEntry[]
  trend:            WeightTrend | null
  bmi:              WeightBmi | null
  goal_suggestion:  GoalSuggestion | null
}

export type WeightRange = 30 | 90 | 180

// Singleton state — shared across all calls to useWeight()
const history = ref<WeightHistory | null>(null)
const loading = ref(false)
const saving  = ref(false)

export function useWeight() {
  const { user } = useAuth()

  async function fetchHistory(range: WeightRange = 30): Promise<void> {
    loading.value = true
    try {
      history.value = await apiFetch<WeightHistory>(`/weight/history?range=${range}`)
    } finally {
      loading.value = false
    }
  }

  async function logWeight(payload: { weight_kg: number; logged_date?: string; note?: string }): Promise<GoalSuggestion | null> {
    saving.value = true
    try {
      const res = await apiFetch<{ entry: WeightEntry; current_weight_kg: number; goal_suggestion: GoalSuggestion | null }>('/weight/log', {
        method: 'POST',
        body:   payload,
      })

      if (user.value) user.value.weight_kg = res.current_weight_kg
      await fetchHistory(history.value?.range ?? 30)

      return res.goal_suggestion
    } finally {
      saving.value = false
    }
  }

  async function deleteEntry(id: number): Promise<void> {
    const res = await apiFetch<{ current_weight_kg: number }>(`/weight/log/${id}`, { method: 'DELETE' })
    if (user.value) user.value.weight_kg = res.current_weight_kg
    await fetchHistory(history.value?.range ?? 30)
  }

  async function applyGoal(calorieGoal: number): Promise<void> {
    await apiFetch('/weight/apply-goal', { method: 'POST', body: { calorie_goal: calorieGoal } })
    if (user.value) user.value.calorie_goal = calorieGoal
    if (history.value) history.value.goal_suggestion = null
  }

  return {
    history, loading, saving,
    fetchHistory, logWeight, deleteEntry, applyGoal,
  }
}
