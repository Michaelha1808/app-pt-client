export interface ChatMessage {
  id: number
  role: 'user' | 'ai'
  text: string
  time: string
}

/** Payload gửi lên API (chỉ role + text) */
export interface ChatTurn {
  role: 'user' | 'ai'
  text: string
}

export type ChatStreamEvent =
  | { type: 'text'; delta: string }
  | { type: 'error'; message: string }
