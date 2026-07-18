export interface MealLogEntry {
  id: number
  food_name: string
  serving: string | null
  image_url?: string | null   // ảnh đã chụp; null/không có → nhập tay, dùng icon
  calories: number
  protein: number
  carbs: number
  fat: number
  sodium: number
  ai_advice?: string | null   // lời khuyên AI đã lưu → xem lại phần phân tích
  logged_at: string
}

// ── Lịch sử gộp ăn uống + tập luyện (endpoint /food/timeline) ──
export interface TimelineMeal {
  kind: 'meal'
  id: number
  food_name: string
  serving: string | null
  image_url?: string | null
  calories: number
  protein: number
  carbs: number
  fat: number
  sodium: number
  ai_advice?: string | null
  at: string          // ISO8601
  date: string        // YYYY-MM-DD
  time: string        // HH:mm
}

export interface TimelineActivity {
  kind: 'activity'
  id: number
  provider: string
  source: 'provider' | 'manual'
  type: string
  name: string | null
  duration_seconds: number
  distance_meters: number | null
  calories: number | null
  at: string
  date: string
  time: string
}

export type TimelineEntry = TimelineMeal | TimelineActivity

export interface TimelineDay {
  date: string
  day_label: string
  day_num: number
  intake: number
  burned: number
}

export interface TimelineResult {
  from: string
  to: string
  total_intake: number
  total_burned: number
  total_protein: number
  total_carbs: number
  total_fat: number
  meal_count: number
  activity_count: number
  days: TimelineDay[]
  entries: TimelineEntry[]
}

export interface TodayStats {
  total_calories: number
  total_protein: number
  total_carbs: number
  total_fat: number
  calories_burned: number
  meals: MealLogEntry[]
}

export interface WeekDay {
  date: string
  day_label: string
  total_calories: number
}

export interface HistoryStats extends TodayStats {
  date: string
  week: WeekDay[]
}
