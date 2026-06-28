import { apiFetch } from '@/utils/api'
import type { HealthActivity, HealthConnection, ManualActivityInput } from '@/types/health'
import { type MealStreakResult, useStreak } from '@/composables/useStreak'

// Singleton state — shared across all calls to useHealthIntegration()
const connections = ref<HealthConnection[]>([])
const available   = ref<string[]>([])
const activities  = ref<HealthActivity[]>([])
const loading     = ref(false)
const page        = ref(1)
const lastPage    = ref(1)

export function useHealthIntegration() {
  const { onMealLogged } = useStreak()

  async function fetchConnections(): Promise<void> {
    try {
      const res = await apiFetch<{ connections: HealthConnection[]; available_providers: string[] }>('/integrations')
      connections.value = res.connections
      available.value   = res.available_providers ?? []
    } catch {
      connections.value = []
      available.value   = []
    }
  }

  async function fetchActivities(reset = true): Promise<void> {
    if (loading.value) return
    loading.value = true
    try {
      const target = reset ? 1 : page.value + 1
      const res = await apiFetch<{ data: HealthActivity[]; meta: { current_page: number; last_page: number } }>(
        `/integrations/activities?page=${target}`,
      )
      activities.value = reset ? res.data : [...activities.value, ...res.data]
      page.value       = res.meta.current_page
      lastPage.value   = res.meta.last_page
    } catch {
      if (reset) activities.value = []
    } finally {
      loading.value = false
    }
  }

  /** Log buổi tập thủ công. Trả về activity vừa tạo (hoặc null nếu lỗi). */
  async function logManual(input: ManualActivityInput): Promise<HealthActivity | null> {
    try {
      const res = await apiFetch<{ activity: HealthActivity; streak: MealStreakResult }>(
        '/integrations/activities/manual',
        { method: 'POST', body: input },
      )
      activities.value = [res.activity, ...activities.value]
      if (res.streak) onMealLogged(res.streak)
      return res.activity
    } catch {
      return null
    }
  }

  /** Bắt đầu kết nối provider: lấy authorize URL rồi redirect cả tab (giống flow OAuth login). */
  async function connect(provider: string): Promise<void> {
    const res = await apiFetch<{ url: string }>(`/integrations/${provider}/connect`)
    window.location.href = res.url
  }

  async function disconnect(provider: string): Promise<boolean> {
    try {
      await apiFetch(`/integrations/${provider}`, { method: 'DELETE' })
      connections.value = connections.value.filter(c => c.provider !== provider)
      return true
    } catch {
      return false
    }
  }

  async function deleteActivity(id: number): Promise<boolean> {
    try {
      await apiFetch(`/integrations/activities/${id}`, { method: 'DELETE' })
      activities.value = activities.value.filter(a => a.id !== id)
      return true
    } catch {
      return false
    }
  }

  const hasMore = computed(() => page.value < lastPage.value)

  const isConnected = (provider: string) =>
    connections.value.some(c => c.provider === provider && c.status === 'active')

  const isAvailable = (provider: string) => available.value.includes(provider)

  return {
    connections, available, activities, loading, hasMore, isConnected, isAvailable,
    fetchConnections, fetchActivities, logManual, deleteActivity, connect, disconnect,
  }
}
