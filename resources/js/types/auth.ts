export interface User {
  id: string
  email: string
  name: string
  avatar_url: string | null
  provider: 'email' | 'google' | 'apple' | 'facebook'
  email_verified: boolean
  role?: 'user' | 'admin'
  status?: 'active' | 'suspended'
  birth_year: number | null
  gender: 'male' | 'female' | 'other' | null
  activity_level: 'sedentary' | 'light' | 'moderate' | 'active' | 'very_active' | null
  goal: 'lose' | 'maintain' | 'gain' | null
  height_cm: number | null
  weight_kg: number | null
  calorie_goal: number | null
  calorie_goal_manual: boolean
  morning_notify: string | null
  evening_notify: string | null
  calorie_streak: number
}

export interface UpdateProfilePayload {
  name?: string
  birth_year?: number
  gender?: 'male' | 'female' | 'other'
  activity_level?: 'sedentary' | 'light' | 'moderate' | 'active' | 'very_active'
  goal?: 'lose' | 'maintain' | 'gain'
  height_cm?: number
  weight_kg?: number
  calorie_goal?: number
  calorie_goal_manual?: boolean
  morning_notify?: string
  evening_notify?: string
}

export interface AuthResponse {
  access_token: string
  token_type: string
  user: User
}

export interface RegisterPayload {
  email: string
  password: string
  name: string
  birth_year: number
  gender: 'male' | 'female' | 'other'
  activity_level: 'sedentary' | 'light' | 'moderate' | 'active' | 'very_active'
  goal: 'lose' | 'maintain' | 'gain'
  height_cm: number
  weight_kg: number
  calorie_goal: number
  morning_notify: string
  evening_notify: string
}
