export interface MealLogEntry {
  id: number
  food_name: string
  serving: string | null
  calories: number
  protein: number
  carbs: number
  fat: number
  sodium: number
  logged_at: string
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
