export type PlanScope = 'daily' | 'monthly'
export type MealSlot = 'breakfast' | 'lunch' | 'dinner' | 'snack'
export type WorkoutType = 'cardio' | 'strength' | 'flexibility'
export type Intensity = 'low' | 'medium' | 'high'

export interface PlannedMeal {
  slot: MealSlot
  name: string
  items: string[]
  calories: number
  protein: number
  carbs: number
  fat: number
}

export interface PlannedWorkout {
  name: string
  type: WorkoutType
  duration_min: number
  intensity: Intensity
  est_calories_burned: number
}

export interface DailyPlan {
  summary: string
  target_calories: number
  target_macros: { protein: number; carbs: number; fat: number }
  water_target_ml: number
  meals: PlannedMeal[]
  workouts: PlannedWorkout[]
  tips: string[]
}

export interface WeeklyFocus {
  week: number
  focus: string
  note: string
}

export interface WorkoutSplit {
  day: string
  activity: string
  duration_min: number
}

export interface MonthlyPlan {
  summary: string
  avg_daily_calories: number
  target_macros: { protein: number; carbs: number; fat: number }
  expected_weight_change_kg: number
  weekly_focus: WeeklyFocus[]
  weekly_workout_split: WorkoutSplit[]
  tips: string[]
}

export type AnyPlan = DailyPlan | MonthlyPlan

export interface PlanResponse {
  plan: AnyPlan | null
  reasoning?: string
  target_date?: string
  is_stale?: boolean
  needs_generation?: boolean
  reason?: 'stale' | 'missing'
  generated_at?: string
}

export type PlanStreamEvent =
  | { type: 'plan'; data: AnyPlan }
  | { type: 'text'; delta: string }
  | { type: 'error'; message: string }
