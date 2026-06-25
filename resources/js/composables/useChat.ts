import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import type { ChatStreamEvent, ChatTurn } from '@/types/chat'

const API_URL = import.meta.env.VITE_API_URL as string

export function useChat() {
  const streaming = ref(false)
  const error     = ref<string | null>(null)

  /**
   * Gửi lịch sử hội thoại, stream phản hồi AI.
   * `onDelta` được gọi mỗi khi có thêm text → cập nhật tin nhắn đang hiển thị.
   */
  async function send(history: ChatTurn[], onDelta: (delta: string) => void) {
    const store = useAuthStore()

    streaming.value = true
    error.value     = null

    try {
      const headers: Record<string, string> = { 'Content-Type': 'application/json' }
      if (store.token) headers['Authorization'] = `Bearer ${store.token}`

      const response = await fetch(`${API_URL}/chat`, {
        method: 'POST',
        headers,
        credentials: 'include',
        body: JSON.stringify({ messages: history }),
      })

      if (!response.ok || !response.body) {
        const errBody = await response.json().catch(() => ({}))
        throw new Error((errBody as any).message ?? 'Không thể kết nối trợ lý AI')
      }

      const reader  = response.body.getReader()
      const decoder = new TextDecoder()
      let buffer    = ''

      while (true) {
        const { done, value } = await reader.read()
        if (done) break

        buffer += decoder.decode(value, { stream: true })
        const lines = buffer.split('\n')
        buffer = lines.pop() ?? ''

        for (const line of lines) {
          if (!line.startsWith('data: ')) continue
          const raw = line.slice(6).trim()
          if (raw === '[DONE]') continue

          try {
            const event = JSON.parse(raw) as ChatStreamEvent
            if (event.type === 'text') onDelta(event.delta)
            else if (event.type === 'error') error.value = event.message
          } catch {
            // bỏ qua event JSON không hợp lệ
          }
        }
      }
    } catch (e: any) {
      if (e?.message !== 'auth:session_expired') {
        error.value = e?.message ?? 'Không thể kết nối. Kiểm tra lại mạng.'
      }
    } finally {
      streaming.value = false
    }
  }

  return { streaming, error, send }
}
