import { $fetch } from 'ofetch'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types/auth'

const API_URL = import.meta.env.VITE_API_URL as string

export async function restoreSession(): Promise<void> {
  const store = useAuthStore()
  try {
    const { access_token } = await $fetch<{ access_token: string }>(`${API_URL}/auth/refresh`, {
      method: 'POST', credentials: 'include',
    })
    store.token = access_token
    const { user } = await $fetch<{ user: User }>(`${API_URL}/auth/me`, {
      headers: { Authorization: `Bearer ${access_token}` },
      credentials: 'include',
    })
    store.user = user
  } catch {
    store.token = null
    store.user = null
  } finally {
    store.sessionReady = true
  }
}
