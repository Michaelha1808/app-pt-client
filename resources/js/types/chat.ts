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
  /** Đã thiết lập kế hoạch từ tin nhắn này chưa (để đổi nút thành trạng thái xong). */
  planApplied?: boolean
  /** Ngày đã áp dụng kế hoạch, dạng hiển thị (vd "hôm nay", "ngày mai") — đi kèm planApplied. */
  planAppliedWhen?: string
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
  /** apply_plan: gọi API tạo kế hoạch (theo target_date) · navigate: chuyển trang · prompt: gửi câu hỏi mồi */
  action: 'apply_plan' | 'navigate' | 'prompt'
  to?: string
  text?: string
  /** Chỉ có ở action=apply_plan — ngày áp dụng kế hoạch (YYYY-MM-DD), suy ra từ ngữ cảnh hội thoại (hôm nay/ngày mai) */
  target_date?: string
}

export type ChatStreamEvent =
  | { type: 'text'; delta: string }
  | { type: 'memory'; items: MemoryItem[]; conflicts: MemoryConflict[] }
  | { type: 'actions'; actions: ChatAction[] }
  | { type: 'conversation'; id: number }
  | { type: 'error'; message: string }

/** 1 dòng trong danh sách lịch sử chat (`GET /chat/conversations`) */
export interface ChatConversationSummary {
  id: number
  title: string | null
  preview: string
  message_count: number
  last_message_at: string | null
}

/** Xem lại 1 cuộc trò chuyện đầy đủ (`GET /chat/conversations/{id}`) */
export interface ChatConversationDetail {
  id: number
  title: string | null
  created_at: string
  messages: { role: 'user' | 'ai'; text: string; created_at: string }[]
}
