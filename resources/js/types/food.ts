export interface FoodAnalysisResult {
  food_name: string
  serving: string
  calories: number
  protein: number
  carbs: number
  fat: number
  sodium: number
  confidence: number
  advice_short: string
}

export interface FoodAnalysisContext {
  today_calories: number
  goal: number
}

export type FoodStreamEvent =
  | { type: 'result'; data: FoodAnalysisResult }
  | { type: 'text'; delta: string }
  | { type: 'error'; message: string }
