import { apiFetch } from '@/utils/api'
import type {
  AdminStats, AdminUserRow, AdminUserDetail, Paginated,
  AdminSettings, AuditLogRow, UsersQuery,
  NotificationSegment, NotificationPreview, NotificationCampaign,
  DishRow, DishInput, DatasetStats, DatasetRow, DatasetDetail,
} from '@/types/admin'

function qs(params: Record<string, unknown>): string {
  const sp = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== null && v !== '') sp.append(k, String(v))
  }
  const s = sp.toString()
  return s ? `?${s}` : ''
}

export function useAdmin() {
  const fetchStats = (range = '30d') =>
    apiFetch<AdminStats>(`/admin/stats?range=${range}`)

  const fetchUsers = (params: UsersQuery = {}) =>
    apiFetch<Paginated<AdminUserRow>>(`/admin/users${qs(params as Record<string, unknown>)}`)

  const fetchUser = (id: number | string) =>
    apiFetch<AdminUserDetail>(`/admin/users/${id}`)

  const updateUser = (id: number | string, payload: Record<string, unknown>) =>
    apiFetch<AdminUserDetail>(`/admin/users/${id}`, { method: 'PATCH', body: payload })

  const suspendUser = (id: number | string, reason?: string) =>
    apiFetch<{ status: string }>(`/admin/users/${id}/suspend`, { method: 'POST', body: { reason } })

  const restoreUser = (id: number | string) =>
    apiFetch<{ status: string }>(`/admin/users/${id}/restore`, { method: 'POST' })

  const resetUserPassword = (id: number | string) =>
    apiFetch<{ message: string }>(`/admin/users/${id}/reset-password`, { method: 'POST' })

  const deleteUser = (id: number | string) =>
    apiFetch<void>(`/admin/users/${id}`, { method: 'DELETE' })

  const fetchSettings = () =>
    apiFetch<AdminSettings>('/admin/settings')

  const saveSettings = (payload: Partial<AdminSettings>) =>
    apiFetch<AdminSettings>('/admin/settings', { method: 'PUT', body: payload })

  const testService = (service: 'ai' | 'fcm' | 'mail') =>
    apiFetch<{ ok: boolean; latency_ms?: number; message: string }>(
      `/admin/settings/test/${service}`, { method: 'POST' },
    )

  const fetchAuditLogs = (params: Record<string, unknown> = {}) =>
    apiFetch<Paginated<AuditLogRow>>(`/admin/audit-logs${qs(params)}`)

  const previewNotification = (segment: NotificationSegment) =>
    apiFetch<NotificationPreview>('/admin/notifications/preview', { method: 'POST', body: { segment } })

  const sendNotification = (payload: { title: string; body: string; url?: string; segment: NotificationSegment }) =>
    apiFetch<NotificationCampaign>('/admin/notifications', { method: 'POST', body: payload })

  const fetchCampaigns = (params: Record<string, unknown> = {}) =>
    apiFetch<Paginated<NotificationCampaign>>(`/admin/notifications${qs(params)}`)

  // ── Thư viện món ăn (nutrition DB) ──
  const fetchDishes = (params: { q?: string; page?: number; per_page?: number } = {}) =>
    apiFetch<Paginated<DishRow>>(`/admin/dishes${qs(params as Record<string, unknown>)}`)

  const createDish = (payload: DishInput) =>
    apiFetch<DishRow>('/admin/dishes', { method: 'POST', body: payload })

  const updateDish = (id: number, payload: DishInput) =>
    apiFetch<DishRow>(`/admin/dishes/${id}`, { method: 'PUT', body: payload })

  const deleteDish = (id: number) =>
    apiFetch<{ message: string }>(`/admin/dishes/${id}`, { method: 'DELETE' })

  // ── Dataset nhận diện ──
  const fetchDatasetStats = () =>
    apiFetch<DatasetStats>('/admin/dataset/stats')

  const fetchDataset = (params: { only_corrections?: boolean; input_type?: string; page?: number } = {}) =>
    apiFetch<Paginated<DatasetRow>>(`/admin/dataset${qs(params as Record<string, unknown>)}`)

  const fetchDatasetSample = (id: number) =>
    apiFetch<DatasetDetail>(`/admin/dataset/${id}`)

  const deleteDatasetSample = (id: number) =>
    apiFetch<{ message: string }>(`/admin/dataset/${id}`, { method: 'DELETE' })

  return {
    fetchStats, fetchUsers, fetchUser, updateUser, suspendUser, restoreUser,
    resetUserPassword, deleteUser, fetchSettings, saveSettings, testService, fetchAuditLogs,
    previewNotification, sendNotification, fetchCampaigns,
    fetchDishes, createDish, updateDish, deleteDish,
    fetchDatasetStats, fetchDataset, fetchDatasetSample, deleteDatasetSample,
  }
}
