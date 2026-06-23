import { apiFetch } from '@/utils/api'

export interface StreakData {
  current_streak:        number
  best_streak:           number
  last_activity_date:    string | null
  freeze_tokens:         number
  freeze_last_used_date: string | null
  can_use_freeze:        boolean
  is_logged_today:       boolean
  streak_at_risk:        boolean
  achieved_milestones:   number[]
  next_milestone:        number | null
}

export interface MealStreakResult {
  current_streak: number
  is_new_day:     boolean
  new_milestone:  number | null
}

export const MILESTONE_META: Record<number, { emoji: string; name: string }> = {
  3:   { emoji: '🌱', name: 'Khởi đầu tốt' },
  7:   { emoji: '🥑', name: '1 tuần liên tiếp' },
  14:  { emoji: '⚡', name: '2 tuần mạnh mẽ' },
  30:  { emoji: '💪', name: '1 tháng kiên trì' },
  60:  { emoji: '🏆', name: 'Siêu kiên nhẫn' },
  100: { emoji: '👑', name: 'Huyền thoại' },
}

export const ALL_MILESTONES = [3, 7, 14, 30, 60, 100]

// Singleton state — shared across all calls to useStreak()
const streak       = ref<StreakData | null>(null)
const loading      = ref(false)
const newMilestone = ref<number | null>(null)

export function useStreak() {
  async function fetchStreak(): Promise<void> {
    if (loading.value) return
    loading.value = true
    try {
      streak.value = await apiFetch<StreakData>('/streak')
    } catch {
      // streak không bắt buộc, không block màn hình
    } finally {
      loading.value = false
    }
  }

  async function useFreeze(): Promise<boolean> {
    try {
      const res = await apiFetch<{ freeze_tokens: number; freeze_last_used_date: string }>('/streak/freeze', {
        method: 'POST',
      })
      if (streak.value) {
        streak.value.freeze_tokens         = res.freeze_tokens
        streak.value.freeze_last_used_date = res.freeze_last_used_date
        streak.value.can_use_freeze        = false
      }
      return true
    } catch {
      return false
    }
  }

  function onMealLogged(result: MealStreakResult): void {
    if (!result.is_new_day) return
    if (streak.value) {
      streak.value.current_streak  = result.current_streak
      streak.value.is_logged_today = true
      streak.value.streak_at_risk  = false
    }
    if (result.new_milestone !== null) {
      newMilestone.value = result.new_milestone
    }
  }

  const streakCount = computed(() => streak.value?.current_streak ?? 0)

  const milestoneInfo = computed(() => {
    if (newMilestone.value === null) return null
    return MILESTONE_META[newMilestone.value] ?? null
  })

  // Hiện banner at-risk sau 18:00 nếu chưa log hôm nay
  const showRiskBanner = computed(() => {
    if (!streak.value?.streak_at_risk) return false
    return new Date().getHours() >= 18
  })

  // Token earned tại milestone 7, 14, 21...
  const earnedTokenAtMilestone = computed(() =>
    newMilestone.value !== null && newMilestone.value % 7 === 0
  )

  return {
    streak, loading, newMilestone, streakCount,
    milestoneInfo, showRiskBanner, earnedTokenAtMilestone,
    fetchStreak, useFreeze, onMealLogged,
  }
}
