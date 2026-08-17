import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import type { ChatAction, ChatStreamEvent, ChatTurn } from '@/types/chat'
import type { MemoryItem, MemoryConflict } from '@/types/preference'

const API_URL = import.meta.env.VITE_API_URL as string

export function useChat() {
  const streaming = ref(false)
  const error     = ref<string | null>(null)

  /**
   * Gửi lịch sử hội thoại, stream phản hồi AI.
   * `onDelta` được gọi mỗi khi có thêm text → cập nhật tin nhắn đang hiển thị.
   * `onMemory` (tuỳ chọn) được gọi khi AI ghi nhớ sở thích mới từ lượt vừa gửi.
   * `conversationId` (tuỳ chọn, chỉ áp dụng khi đăng nhập): nối tiếp vào 1 cuộc trò chuyện
   * đã lưu server-side; bỏ trống để server tự tạo mới. `onConversation` báo lại id vừa tạo/dùng.
   */
  async function send(
    history: ChatTurn[],
    onDelta: (delta: string) => void,
    onMemory?: (items: MemoryItem[], conflicts: MemoryConflict[]) => void,
    onActions?: (actions: ChatAction[]) => void,
    conversationId?: number | null,
    onConversation?: (id: number) => void,
  ) {
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
        body: JSON.stringify({ messages: history, conversation_id: conversationId ?? undefined }),
      })

      if (!response.ok || !response.body) {
        const errBody = await response.json().catch(() => ({}))
        // Backend trả lỗi dạng { detail: "..." } (xem bootstrap/app.php ValidationException handler),
        // không phải { message: "..." } — dùng sai key khiến lỗi thật (vd 422 validate) bị nuốt
        // thành thông báo chung chung "Không thể kết nối trợ lý AI", không phản ánh đúng nguyên nhân.
        throw new Error((errBody as any).detail ?? (errBody as any).message ?? 'Không thể kết nối trợ lý AI')
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
            else if (event.type === 'memory') onMemory?.(event.items, event.conflicts ?? [])
            else if (event.type === 'actions') onActions?.(event.actions ?? [])
            else if (event.type === 'conversation') onConversation?.(event.id)
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
