import { ref } from 'vue'
import { apiFetch } from '@/utils/api'
import { useAuthStore } from '@/stores/auth'
import type { AnyPlan, PlanResponse, PlanScope, PlanStreamEvent } from '@/types/plan'

const API_URL = import.meta.env.VITE_API_URL as string

export function useMealPlan() {
  const plan       = ref<AnyPlan | null>(null)
  const reasoning  = ref('')
  const isStale    = ref(false)
  const loading    = ref(false)   // GET
  const generating = ref(false)   // POST stream
  const applying   = ref(false)   // POST /plan/apply
  const error      = ref<string | null>(null)
  /** Kế hoạch đang xem là bản vừa sinh, CHƯA lưu — chờ user bấm "Áp dụng". */
  const isDraft    = ref(false)

  async function fetchPlan(scope: PlanScope = 'daily') {
    loading.value = true
    error.value   = null
    try {
      const res = await apiFetch<PlanResponse>(`/plan?scope=${scope}`)
      plan.value      = res.plan
      reasoning.value = res.reasoning ?? ''
      isStale.value   = res.is_stale ?? false
      isDraft.value   = false
    } catch (e: any) {
      if (e?.message !== 'auth:session_expired') error.value = 'Không tải được kế hoạch.'
    } finally {
      loading.value = false
    }
  }

  /** Lưu bản nháp vừa sinh thành kế hoạch đang dùng. Trả về true nếu thành công. */
  async function apply(scope: PlanScope = 'daily'): Promise<boolean> {
    applying.value = true
    error.value    = null
    try {
      const res = await apiFetch<PlanResponse & { message: string }>('/plan/apply', {
        method: 'POST',
        body:   { scope },
      })
      plan.value      = res.plan
      reasoning.value = res.reasoning ?? reasoning.value
      isStale.value   = false
      isDraft.value   = false
      return true
    } catch (e: any) {
      if (e?.message !== 'auth:session_expired') {
        error.value = e?.data?.message ?? e?.data?.detail ?? 'Không thể áp dụng kế hoạch.'
      }
      return false
    } finally {
      applying.value = false
    }
  }

  async function generate(scope: PlanScope = 'daily') {
    const store = useAuthStore()
    plan.value      = null
    reasoning.value = ''
    isStale.value   = false
    isDraft.value   = false
    error.value     = null
    generating.value = true

    try {
      const headers: Record<string, string> = { 'Content-Type': 'application/json' }
      if (store.token) headers['Authorization'] = `Bearer ${store.token}`

      const res = await fetch(`${API_URL}/plan/generate`, {
        method: 'POST',
        headers,
        credentials: 'include',
        body: JSON.stringify({ scope }),
      })
      if (!res.ok || !res.body) {
        const errBody = await res.json().catch(() => ({}))
        throw new Error((errBody as any).message ?? 'Không thể tạo kế hoạch')
      }

      const reader  = res.body.getReader()
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
            const ev = JSON.parse(raw) as PlanStreamEvent
            if (ev.type === 'plan') {
              plan.value    = ev.data
              isDraft.value = true   // mới chỉ là bản xem trước, chưa lưu
            }
            else if (ev.type === 'text') reasoning.value += ev.delta
            else if (ev.type === 'error') error.value = ev.message
          } catch { /* bỏ qua */ }
        }
      }
    } catch (e: any) {
      if (e?.message !== 'auth:session_expired') error.value = e?.message ?? 'Không thể kết nối.'
    } finally {
      generating.value = false
    }
  }

  return { plan, reasoning, isStale, isDraft, loading, generating, applying, error, fetchPlan, generate, apply }
}
