export interface ChatMessage {
  id: number
  role: 'user' | 'ai'
  text: string
  time: string
  /** Sở thích AI vừa ghi nhớ từ lượt hội thoại trước tin nhắn này (nếu có). */
  memory?: MemoryItem[]
  /** Xung đột sở thích cần người dùng xác nhận. */
  conflicts?: MemoryConflict[]
  /** Nút hành động gợi ý dưới tin nhắn AI. */
  actions?: ChatAction[]
  /** Đã thiết lập kế hoạch hôm nay từ tin nhắn này chưa (để đổi nút thành trạng thái xong). */
  planApplied?: boolean
}

/** Payload gửi lên API (chỉ role + text) */
export interface ChatTurn {
  role: 'user' | 'ai'
  text: string
}

import type { MemoryItem, MemoryConflict } from '@/types/preference'

/** Nút hành động gợi ý sau khi AI tư vấn xong. */
export interface ChatAction {
  id: string
  label: string
  /** apply_plan: gọi API tạo kế hoạch hôm nay · navigate: chuyển trang · prompt: gửi câu hỏi mồi */
  action: 'apply_plan' | 'navigate' | 'prompt'
  to?: string
  text?: string
}

export type ChatStreamEvent =
  | { type: 'text'; delta: string }
  | { type: 'memory'; items: MemoryItem[]; conflicts: MemoryConflict[] }
  | { type: 'actions'; actions: ChatAction[] }
  | { type: 'error'; message: string }
