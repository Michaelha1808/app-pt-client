import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'

const API_URL = import.meta.env.VITE_API_URL as string

export interface MealAdvicePayload {
  dishes: { name: string; calories: number }[]
  total_calories: number
  context?: { today_calories?: number; goal?: number }
}

export function useMealAdvice() {
  const advice    = ref('')
  const streaming = ref(false)
  const error     = ref<string | null>(null)

  let abortCtl: AbortController | null = null

  async function fetchAdvice(payload: MealAdvicePayload) {
    // Sửa món / đổi số lượng liên tiếp → huỷ luồng cũ, nếu không 2 stream cùng ghi vào
    // `advice` và nhận xét hiển thị bị trộn lẫn giữa 2 lần.
    abortCtl?.abort()
    const abort = new AbortController()
    abortCtl    = abort

    advice.value    = ''
    error.value     = null
    streaming.value = true

    try {
      const store = useAuthStore()
      const headers: Record<string, string> = { 'Content-Type': 'application/json' }
      if (store.token) headers['Authorization'] = `Bearer ${store.token}`

      const res = await fetch(`${API_URL}/food/advise-meal`, {
        method: 'POST',
        headers,
        credentials: 'include',
        signal: abort.signal,
        body: JSON.stringify(payload),
      })
      if (!res.ok || !res.body) throw new Error('Không thể tạo nhận xét')

      const reader  = res.body.getReader()
      const decoder = new TextDecoder()
      let buffer    = ''

      while (true) {
        const { done, value } = await reader.read()
        if (done || abort.signal.aborted) break
        buffer += decoder.decode(value, { stream: true })
        const lines = buffer.split('\n')
        buffer = lines.pop() ?? ''
        for (const line of lines) {
          if (!line.startsWith('data: ')) continue
          const raw = line.slice(6).trim()
          if (raw === '[DONE]') continue
          try {
            const ev = JSON.parse(raw) as { type: string; delta?: string; message?: string }
            if (ev.type === 'text' && ev.delta) advice.value += ev.delta
            else if (ev.type === 'error') error.value = ev.message ?? 'Lỗi'
          } catch { /* bỏ qua */ }
        }
      }
    } catch (e: any) {
      if (abort.signal.aborted) return            // lần gọi mới đã tiếp quản
      if (e?.message !== 'auth:session_expired') {
        error.value = e?.message ?? 'Không thể kết nối.'
      }
    } finally {
      if (abortCtl === abort) {
        abortCtl        = null
        streaming.value = false
      }
    }
  }

  /** Bỏ nhận xét đang hiển thị/đang stream (vd: user bỏ chọn hết món) */
  function resetAdvice() {
    abortCtl?.abort()
    abortCtl        = null
    advice.value    = ''
    streaming.value = false
  }

  return { advice, streaming, error, fetchAdvice, resetAdvice }
}
