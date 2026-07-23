// Tích hợp app sức khoẻ + log buổi tập thủ công.

export type ActivityType =
  | 'walk' | 'run' | 'ride' | 'swim' | 'workout' | 'yoga' | 'hike' | 'other'

export type ActivitySource = 'provider' | 'manual'

export interface HealthActivity {
  id: number
  provider: string            // strava | fitbit | garmin | manual
  source: ActivitySource
  type: ActivityType | string
  name: string | null
  started_at: string          // ISO8601
  duration_seconds: number
  distance_meters: number | null
  calories: number | null
}

export interface HealthConnection {
  id: number
  provider: string
  status: 'active' | 'revoked' | 'error'
  scopes: string | null
  last_synced_at: string | null
  connected_at: string
}

export interface ManualActivityInput {
  type: ActivityType
  duration_seconds: number
  started_at?: string
  distance_meters?: number | null
  calories?: number | null
  name?: string | null
}

// Metadata hiển thị cho từng loại bài tập (label tiếng Việt + icon).
// tracksDistance: bộ môn có tính theo quãng đường không (gym/tạ, yoga... thì không).
export const ACTIVITY_TYPES: { value: ActivityType; label: string; emoji: string; tracksDistance: boolean }[] = [
  { value: 'walk',    label: 'Đi bộ',   emoji: '🚶',  tracksDistance: true  },
  { value: 'run',     label: 'Chạy bộ', emoji: '🏃',  tracksDistance: true  },
  { value: 'ride',    label: 'Đạp xe',  emoji: '🚴',  tracksDistance: true  },
  { value: 'swim',    label: 'Bơi',     emoji: '🏊',  tracksDistance: true  },
  { value: 'workout', label: 'Gym/Tạ',  emoji: '🏋️', tracksDistance: false },
  { value: 'yoga',    label: 'Yoga',    emoji: '🧘',  tracksDistance: false },
  { value: 'hike',    label: 'Leo núi', emoji: '🥾',  tracksDistance: true  },
  { value: 'other',   label: 'Khác',    emoji: '💪',  tracksDistance: false },
]

export function activityMeta(type: string) {
  return ACTIVITY_TYPES.find(t => t.value === type) ?? { value: 'other', label: type, emoji: '💪', tracksDistance: false }
}
