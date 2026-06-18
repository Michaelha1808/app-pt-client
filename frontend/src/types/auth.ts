export interface User {
  id: string
  email: string
  name: string
  avatar_url: string | null
  provider: 'email' | 'google' | 'apple'
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
  height_cm: number
  weight_kg: number
  calorie_goal: number
  morning_notify: string
  evening_notify: string
}
