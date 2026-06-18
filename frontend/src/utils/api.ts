import type { NitroFetchOptions, NitroFetchRequest } from 'nitropack'
import type { User } from '~/types/auth'

// Shared promise so concurrent 401s only trigger one refresh call
let _refreshPromise: Promise<string> | null = null

async function _doRefresh(apiUrl: string): Promise<string> {
  const res = await $fetch<{ access_token: string }>(`${apiUrl}/auth/refresh`, {
    method: 'POST',
    credentials: 'include',
  })
  return res.access_token
}

export async function apiFetch<T>(
  path: string,
  init: NitroFetchOptions<NitroFetchRequest> = {},
): Promise<T> {
  const config = useRuntimeConfig()
  const token = useState<string | null>('auth.token')
  const apiUrl = config.public.apiUrl as string

  const buildHeaders = (currentToken: string | null): Headers => {
    const headers = new Headers(init.headers as HeadersInit)
    if (!headers.has('Content-Type')) headers.set('Content-Type', 'application/json')
    if (currentToken) headers.set('Authorization', `Bearer ${currentToken}`)
    return headers
  }

  try {
    return await $fetch<T>(`${apiUrl}${path}`, {
      ...init,
      headers: buildHeaders(token.value),
      credentials: 'include',
    })
  } catch (err: unknown) {
    const status = (err as any)?.response?.status ?? (err as any)?.statusCode

    if (status === 401 && token.value) {
      try {
        if (!_refreshPromise) {
          _refreshPromise = _doRefresh(apiUrl).finally(() => {
            _refreshPromise = null
          })
        }
        const newToken = await _refreshPromise
        token.value = newToken
        // Retry original request with new token
        return await $fetch<T>(`${apiUrl}${path}`, {
          ...init,
          headers: buildHeaders(newToken),
          credentials: 'include',
        })
      } catch {
        // Refresh failed — clear state and redirect
        token.value = null
        useState<User | null>('auth.user').value = null
        if (import.meta.client) await navigateTo('/auth/login')
        // Throw a sentinel so composable error handlers can suppress the flash
        throw new Error('auth:session_expired')
      }
    }

    throw err
  }
}
