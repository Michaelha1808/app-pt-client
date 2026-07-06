export type PreferenceKind = 'allergy' | 'dislike' | 'like' | 'diet' | 'habit'
export type PreferenceSource = 'chat' | 'manual' | 'inferred'

export interface UserPreference {
  id: number
  kind: PreferenceKind
  label: string
  source: PreferenceSource
  last_confirmed_at: string | null
  created_at: string | null
}

/** Mục ghi nhớ AI vừa trích từ hội thoại (gửi qua SSE event `memory`). */
export interface MemoryItem {
  id: number
  kind: PreferenceKind
  label: string
}

/** Xung đột: value đã tồn tại nhưng AI trích ra "thái độ" khác → hỏi người dùng. */
export interface MemoryConflict {
  id: number
  label: string
  current_kind: PreferenceKind
  suggested_kind: PreferenceKind
}
