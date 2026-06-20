import { $fetch } from 'ofetch'
import type { FetchOptions } from 'ofetch'
import { useAuthStore } from '@/stores/auth'
import { router } from '@/router'

const API_URL = import.meta.env.VITE_API_URL as string
let _refreshPromise: Promise<string> | null = null

async function _doRefresh(): Promise<string> {
  const res = await $fetch<{ access_token: string }>(`${API_URL}/auth/refresh`, {
    method: 'POST', credentials: 'include',
  })
  return res.access_token
}

export async function apiFetch<T>(path: string, init: FetchOptions = {}): Promise<T> {
  const store = useAuthStore()
  const buildHeaders = (tok: string | null): Record<string, string> => {
    const h: Record<string, string> = {}
    if (!(init.body instanceof FormData)) h['Content-Type'] = 'application/json'
    if (tok) h['Authorization'] = `Bearer ${tok}`
    return { ...h, ...(init.headers as Record<string, string> ?? {}) }
  }
  try {
    return await $fetch<T>(`${API_URL}${path}`, { ...init, headers: buildHeaders(store.token), credentials: 'include' })
  } catch (err: unknown) {
    const status = (err as any)?.response?.status ?? (err as any)?.statusCode
    if (status === 401 && store.token) {
      try {
        if (!_refreshPromise) _refreshPromise = _doRefresh().finally(() => { _refreshPromise = null })
        const newToken = await _refreshPromise
        store.token = newToken
        return await $fetch<T>(`${API_URL}${path}`, { ...init, headers: buildHeaders(newToken), credentials: 'include' })
      } catch {
        store.token = null
        store.user = null
        router.push('/auth/login')
        throw new Error('auth:session_expired')
      }
    }
    throw err
  }
}
