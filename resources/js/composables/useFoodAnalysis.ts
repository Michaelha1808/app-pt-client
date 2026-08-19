import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import type { FoodAnalysisResult, FoodAnalysisContext, FoodStreamEvent } from '@/types/food'

/** Số liệu món ăn dùng để sinh lời khuyên — bản user đã sửa, không phải bản AI đoán ban đầu */
export interface AdviceInput {
  food_name: string
  serving?:  string
  calories:  number
  protein?:  number
  carbs?:    number
  fat?:      number
  sodium?:   number
}

const API_URL = import.meta.env.VITE_API_URL as string

export function useFoodAnalysis() {
  const result       = ref<FoodAnalysisResult | null>(null)
  const streamingText = ref('')
  const streamDone   = ref(false)
  const loading      = ref(false)
  const error        = ref<string | null>(null)

  let adviceAbort: AbortController | null = null

  // Đọc chung cho cả /food/analyze (có event "result") và /food/advise (chỉ có "text") — tránh
  // lặp lại vòng đọc SSE giống hệt nhau ở 2 nơi.
  async function readSSE(
    response: Response,
    onResult?: (data: FoodAnalysisResult) => void,
    signal?: AbortSignal,
  ) {
    if (!response.body) {
      throw new Error('Không nhận được phản hồi từ server')
    }

    const reader  = response.body.getReader()
    const decoder = new TextDecoder()
    let buffer    = ''

    while (true) {
      const { done, value } = await reader.read()
      if (done || signal?.aborted) break

      buffer += decoder.decode(value, { stream: true })
      const lines = buffer.split('\n')
      buffer = lines.pop() ?? '' // giữ dòng chưa hoàn chỉnh

      for (const line of lines) {
        if (!line.startsWith('data: ')) continue
        const raw = line.slice(6).trim()
        if (raw === '[DONE]') { streamDone.value = true; continue }

        try {
          const event = JSON.parse(raw) as FoodStreamEvent
          if (event.type === 'result') onResult?.(event.data)
          else if (event.type === 'text') streamingText.value += event.delta
          else if (event.type === 'error') error.value = event.message
        } catch {
          // bỏ qua event JSON không hợp lệ
        }
      }
    }
  }

  async function analyze(options: {
    image?: string | null
    text?: string | null
    context: FoodAnalysisContext
  }) {
    const store = useAuthStore()

    result.value        = null
    streamingText.value = ''
    streamDone.value    = false
    error.value         = null
    loading.value       = true

    try {
      const headers: Record<string, string> = { 'Content-Type': 'application/json' }
      if (store.token) headers['Authorization'] = `Bearer ${store.token}`

      const response = await fetch(`${API_URL}/food/analyze`, {
        method: 'POST',
        headers,
        credentials: 'include',
        body: JSON.stringify(options),
      })

      // Lỗi trước khi stream (4xx/5xx trả về JSON)
      if (!response.ok && response.status !== 400) {
        const errBody = await response.json().catch(() => ({}))
        throw new Error((errBody as any).message ?? 'Có lỗi xảy ra, vui lòng thử lại')
      }

      await readSSE(response, (data) => { result.value = data })
    } catch (e: any) {
      if (e?.message !== 'auth:session_expired') {
        error.value = e?.message ?? 'Không thể kết nối. Kiểm tra lại mạng.'
      }
    } finally {
      loading.value    = false
      streamDone.value = true
    }
  }

  /**
   * Sinh lại lời khuyên cho món user vừa sửa (không chạy lại nhận diện ảnh) — dùng khi AI
   * nhận diện sai và user chỉnh lại trong Result.vue, để lời khuyên bám đúng dữ liệu đã sửa
   * thay vì "đóng băng" theo lần đoán ban đầu.
   *
   * Gửi cả khẩu phần + macro chứ không chỉ tên/calo: sửa "1 tô" → "2 tô" hay chỉnh lại đạm/
   * chất béo cũng làm lời khuyên khác đi.
   */
  async function refetchAdvice(food: AdviceInput, context: FoodAnalysisContext) {
    const store = useAuthStore()

    // Sửa liên tiếp → huỷ luồng cũ, nếu không 2 stream cùng ghi vào streamingText và lời
    // khuyên hiển thị bị trộn lẫn của cả 2 lần sửa.
    adviceAbort?.abort()
    const abort = new AbortController()
    adviceAbort = abort

    streamingText.value = ''
    streamDone.value    = false
    error.value         = null

    try {
      const headers: Record<string, string> = { 'Content-Type': 'application/json' }
      if (store.token) headers['Authorization'] = `Bearer ${store.token}`

      const response = await fetch(`${API_URL}/food/advise`, {
        method: 'POST',
        headers,
        credentials: 'include',
        signal: abort.signal,
        body: JSON.stringify({
          food_name: food.food_name,
          serving:   food.serving ?? null,
          calories:  food.calories,
          protein:   food.protein ?? 0,
          carbs:     food.carbs   ?? 0,
          fat:       food.fat     ?? 0,
          sodium:    food.sodium  ?? 0,
          context,
        }),
      })

      if (!response.ok) {
        const errBody = await response.json().catch(() => ({}))
        throw new Error((errBody as any).message ?? 'Có lỗi xảy ra, vui lòng thử lại')
      }

      await readSSE(response, undefined, abort.signal)
    } catch (e: any) {
      if (abort.signal.aborted) return          // lần sửa mới đã tiếp quản
      if (e?.message !== 'auth:session_expired') {
        error.value = e?.message ?? 'Không thể kết nối. Kiểm tra lại mạng.'
      }
    } finally {
      if (adviceAbort === abort) {
        adviceAbort      = null
        streamDone.value = true
      }
    }
  }

  return { result, streamingText, streamDone, loading, error, analyze, refetchAdvice }
}
