import type { User, AuthResponse, RegisterPayload } from '~/types/auth'

export function useAuth() {
  const config = useRuntimeConfig()
  const user = useState<User | null>('auth.user', () => null)
  const token = useState<string | null>('auth.token', () => null)
  const isGuest = useState<boolean>('auth.guest', () => false)
  // Becomes true after restoreSession completes (set in plugin)
  const sessionReady = useState<boolean>('auth.ready', () => false)

  const isLoggedIn = computed(() => !!user.value)

  function extractError(err: unknown): string {
    const e = err as any
    // Sentinel thrown by api.ts after redirect — suppress
    if (e?.message === 'auth:session_expired') return ''
    const detail = e?.data?.detail
    if (typeof detail === 'string') return detail
    if (Array.isArray(detail)) return detail.map((d: any) => d.msg).join(', ')
    const status = e?.response?.status ?? e?.statusCode
    if (status === 429) return 'Quá nhiều lần thử. Vui lòng đợi và thử lại.'
    if (e?.message?.toLowerCase().includes('fetch') || e?.message?.toLowerCase().includes('network'))
      return 'Không thể kết nối với máy chủ. Vui lòng thử lại sau.'
    return 'Đã có lỗi xảy ra. Vui lòng thử lại.'
  }

  async function login(email: string, password: string): Promise<void> {
    const res = await apiFetch<AuthResponse>('/auth/login', {
      method: 'POST',
      body: { email, password },
    })
    token.value = res.access_token
    user.value = res.user
    isGuest.value = false
    await navigateTo('/home')
  }

  async function register(payload: RegisterPayload): Promise<void> {
    const res = await apiFetch<AuthResponse>('/auth/register', {
      method: 'POST',
      body: payload,
    })
    token.value = res.access_token
    user.value = res.user
    isGuest.value = false
    await navigateTo('/home')
  }

  async function forgotPassword(email: string): Promise<string> {
    const res = await apiFetch<{ message: string }>('/auth/forgot-password', {
      method: 'POST',
      body: { email },
    })
    return res.message
  }

  async function resetPassword(resetToken: string, newPassword: string): Promise<void> {
    await apiFetch('/auth/reset-password', {
      method: 'POST',
      body: { token: resetToken, new_password: newPassword },
    })
  }

  function loginWithGoogle(): void {
    const redirectUri = `${config.public.appUrl}/auth/callback`
    window.location.href = `${config.public.apiUrl}/auth/google?redirect_uri=${encodeURIComponent(redirectUri)}`
  }

  async function handleOAuthCallback(oauthToken: string): Promise<void> {
    token.value = oauthToken
    isGuest.value = false
    const res = await apiFetch<{ user: User }>('/auth/me')
    user.value = res.user
    await navigateTo('/home')
  }

  async function loginAsGuest(): Promise<void> {
    isGuest.value = true
    await navigateTo('/home')
  }

  async function logout(): Promise<void> {
    try {
      await apiFetch('/auth/logout', { method: 'POST' })
    } catch {}
    token.value = null
    user.value = null
    isGuest.value = false
    const { success } = useToast()
    success('Đã đăng xuất thành công')
    await navigateTo('/auth/login')
  }

  async function refreshToken(): Promise<boolean> {
    try {
      const res = await $fetch<{ access_token: string }>(
        `${config.public.apiUrl}/auth/refresh`,
        { method: 'POST', credentials: 'include' },
      )
      token.value = res.access_token
      return true
    } catch {
      return false
    }
  }

  async function restoreSession(): Promise<void> {
    try {
      const refreshed = await refreshToken()
      if (!refreshed) return
      const res = await apiFetch<{ user: User }>('/auth/me')
      user.value = res.user
    } catch {
      token.value = null
      user.value = null
    } finally {
      sessionReady.value = true
    }
  }

  return {
    user,
    token,
    isLoggedIn,
    isGuest,
    sessionReady,
    login,
    register,
    forgotPassword,
    resetPassword,
    loginWithGoogle,
    handleOAuthCallback,
    loginAsGuest,
    logout,
    refreshToken,
    restoreSession,
    extractError,
  }
}
