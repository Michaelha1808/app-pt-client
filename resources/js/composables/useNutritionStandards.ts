import { ref } from 'vue'
import { apiFetch } from '@/utils/api'

/**
 * Chuẩn dinh dưỡng công khai (activity_levels + citations) từ backend.
 * Cache toàn app: chỉ fetch 1 lần, dùng cho picker mức vận động, footer nguồn tham chiếu, v.v.
 */
export interface ActivityLevel {
  label: string
  desc:  string
}

export interface Citation {
  id:     string
  title:  string
  author: string
  year:   number
  url:    string
}

interface Standards {
  activity_levels: Record<string, ActivityLevel>
  citations:       Citation[]
}

const cached  = ref<Standards | null>(null)
const loading = ref(false)

export function useNutritionStandards() {
  async function load(): Promise<Standards | null> {
    if (cached.value) return cached.value
    if (loading.value) return null
    loading.value = true
    try {
      cached.value = await apiFetch<Standards>('/nutrition/standards')
      return cached.value
    } catch {
      return null
    } finally {
      loading.value = false
    }
  }

  return { standards: cached, loading, load }
}

/** Gọi endpoint tính BMR/TDEE/goal/macros cho hồ sơ đang nhập (register flow). */
export interface CalculatePayload {
  birth_year:     number
  gender:         'male' | 'female' | 'other'
  height_cm:      number
  weight_kg:      number
  activity_level: string
  goal:           'lose' | 'maintain' | 'gain'
}

export interface CalculateResult {
  bmr:              number
  tdee:             number
  calorie_goal:     number
  target_macros:    { protein: number; carbs: number; fat: number }
  water_target_ml:  number
  citations:        Citation[]
}

export async function calculateNutrition(p: CalculatePayload): Promise<CalculateResult | null> {
  try {
    return await apiFetch<CalculateResult>('/nutrition/calculate', {
      method: 'POST',
      body: p,
    })
  } catch {
    return null
  }
}
