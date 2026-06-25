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
  const error      = ref<string | null>(null)

  async function fetchPlan(scope: PlanScope = 'daily') {
    loading.value = true
    error.value   = null
    try {
      const res = await apiFetch<PlanResponse>(`/plan?scope=${scope}`)
      plan.value      = res.plan
      reasoning.value = res.reasoning ?? ''
      isStale.value   = res.is_stale ?? false
    } catch (e: any) {
      if (e?.message !== 'auth:session_expired') error.value = 'Không tải được kế hoạch.'
    } finally {
      loading.value = false
    }
  }

  async function generate(scope: PlanScope = 'daily') {
    const store = useAuthStore()
    plan.value      = null
    reasoning.value = ''
    isStale.value   = false
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
            if (ev.type === 'plan') plan.value = ev.data
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

  return { plan, reasoning, isStale, loading, generating, error, fetchPlan, generate }
}
