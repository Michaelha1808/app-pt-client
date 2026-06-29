export interface AdminStats {
  kpi: {
    total_users: number
    new_users_today: number
    active_users_7d: number
    suspended_users: number
    total_meal_logs: number
    meal_logs_today: number
    ai_food_analyses_today: number
    ai_chat_messages_today: number
    push_sent_today: number
    active_streaks: number
  }
  series: {
    new_users: SeriesPoint[]
    meal_logs: SeriesPoint[]
    ai_calls: SeriesPoint[]
  }
  breakdown: {
    by_provider: Record<string, number>
    by_gender: Record<string, number>
  }
}

export interface SeriesPoint {
  date: string
  count: number
}

export interface AdminUserRow {
  id: number
  name: string
  email: string
  avatar_url: string | null
  provider: string
  role: 'user' | 'admin'
  status: 'active' | 'suspended'
  calorie_streak: number
  meal_logs_count: number
  last_seen_at: string | null
  created_at: string
}

export interface AdminUserDetail extends AdminUserRow {
  birth_year: number | null
  gender: string | null
  height_cm: number | null
  weight_kg: number | null
  calorie_goal: number | null
  suspend_reason: string | null
  notify: { morning: boolean; midday: boolean; evening: boolean; email_reengagement: boolean }
  stats: { meal_logs: number; water_logs: number; plans: number; passkeys: number }
  updated_at: string
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; per_page: number; total: number; last_page: number }
}

export interface AdminSettings {
  ai: {
    provider: string
    model: string
    api_key: string | null
    temperature: number
    max_tokens: number
    food_analysis_enabled: boolean
    chat_enabled: boolean
  }
  rate_limit: {
    food_analyze_per_min: number
    chat_per_min: number
    plan_generate_per_min: number
  }
  notifications: {
    fcm_enabled: boolean
    fcm_project_id: string | null
    morning_default: string
    evening_default: string
    reengagement_days: number
  }
  mail: {
    from_address: string | null
    from_name: string | null
    reengagement_enabled: boolean
  }
  oauth: {
    google_enabled: boolean
    facebook_enabled: boolean
  }
  features: {
    registration_open: boolean
    guest_mode_enabled: boolean
    maintenance_mode: boolean
  }
}

export interface AuditLogRow {
  id: number
  admin: { id: number; name: string; email: string } | null
  action: string
  target_type: string | null
  target_id: string | null
  meta: Record<string, unknown> | null
  ip: string | null
  created_at: string
}

export interface NotificationSegment {
  audience: 'all' | 'segment'
  role?: '' | 'user' | 'admin'
  provider?: '' | 'email' | 'google' | 'facebook'
  gender?: '' | 'male' | 'female' | 'other'
  activity?: '' | 'active_7d' | 'inactive_7d' | 'inactive_30d'
  has_streak?: boolean
  only_subscribed?: boolean
}

export interface NotificationPreview {
  audience_count: number
  subscribed_count: number
}

export interface NotificationCampaign {
  id: number
  title: string
  body: string
  url: string | null
  segment: NotificationSegment | null
  audience_count: number
  sent_count: number
  push_count: number
  status: 'queued' | 'sending' | 'done' | 'failed'
  admin: { id: number; name: string } | null
  created_at: string
}

export interface UsersQuery {
  search?: string
  role?: string
  status?: string
  provider?: string
  sort?: string
  order?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

// ── Thư viện món ăn (nutrition DB) ──
export interface DishRow {
  id: number
  name: string
  aliases: string[]
  unit_type: 'countable' | 'portion'
  unit_label: string
  serving: string
  calories: number
  protein: number
  carbs: number
  fat: number
  sodium: number
}

export type DishInput = Omit<DishRow, 'id'>

// ── Dataset nhận diện (AI đoán vs user sửa) ──
export interface DatasetStats {
  total: number
  with_correction: number
  saved: number
  with_image: number
}

export interface DatasetRow {
  id: number
  input_type: 'image' | 'text'
  has_image: boolean
  text_input: string | null
  model: string | null
  ai_count: number
  has_correction: boolean
  saved: boolean
  created_at: string
}

export interface DatasetDishAi {
  food_name: string
  unit_type?: string
  unit_label?: string
  serving?: string
  quantity_default?: number
  calories: number
  protein?: number
  carbs?: number
  fat?: number
  sodium?: number
  confidence?: number
}

export interface DatasetDishCorrected {
  food_name: string
  calories: number
  quantity: number
  selected: boolean
}

export interface DatasetDetail {
  id: number
  input_type: 'image' | 'text'
  text_input: string | null
  model: string | null
  ai_dishes: DatasetDishAi[]
  corrected_dishes: DatasetDishCorrected[] | null
  has_correction: boolean
  saved: boolean
  created_at: string
  image: string | null   // data URI base64
}
