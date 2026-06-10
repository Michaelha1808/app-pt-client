import type { NitroFetchOptions, NitroFetchRequest } from 'nitropack'

export async function apiFetch<T>(
  path: string,
  init: NitroFetchOptions<NitroFetchRequest> = {},
): Promise<T> {
  const config = useRuntimeConfig()
  const headers = new Headers(init.headers as HeadersInit)

  if (!headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }

  return await $fetch<T>(`${config.public.apiUrl}${path}`, {
    ...init,
    headers,
    credentials: 'include',
  })
}
