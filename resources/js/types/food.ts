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

// ── Multi-dish detect ─────────────────────────────────────────────
export type UnitType = 'countable' | 'portion'

export interface DetectedDish {
  food_name: string
  unit_type: UnitType
  unit_label: string          // "cái" | "chén" | "tô" ...
  serving: string
  quantity_default: number
  calories: number            // cho 1 đơn vị
  protein: number
  carbs: number
  fat: number
  sodium: number
  confidence: number
  source?: 'catalog' | 'ai'   // 'catalog' = calo lấy từ thư viện chuẩn; 'ai' = AI ước tính
  dish_id?: number | null     // id món trong thư viện nếu khớp
}

export interface DetectResponse {
  dishes: DetectedDish[]
  detection_id?: number | null   // id mẫu dataset để gửi feedback (AI đoán vs user sửa)
}

/** Dòng trong màn chọn món (UI state) */
export interface DishPick extends DetectedDish {
  selected: boolean
  quantity: number            // countable: nguyên; portion: bội số 0.5
}
