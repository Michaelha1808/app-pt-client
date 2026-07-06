export interface ChatMessage {
  id: number
  role: 'user' | 'ai'
  text: string
  time: string
  /** Sở thích AI vừa ghi nhớ từ lượt hội thoại trước tin nhắn này (nếu có). */
  memory?: MemoryItem[]
  /** Xung đột sở thích cần người dùng xác nhận. */
  conflicts?: MemoryConflict[]
}

/** Payload gửi lên API (chỉ role + text) */
export interface ChatTurn {
  role: 'user' | 'ai'
  text: string
}

import type { MemoryItem, MemoryConflict } from '@/types/preference'

export type ChatStreamEvent =
  | { type: 'text'; delta: string }
  | { type: 'memory'; items: MemoryItem[]; conflicts: MemoryConflict[] }
  | { type: 'error'; message: string }
