import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { apiFetch } from '@/utils/api'
import { useToast } from '@/composables/useToast'
import { useNotifications } from '@/composables/useNotifications'
import type { User, AuthResponse, RegisterPayload } from '@/types/auth'

export function useAuth() {
  const store = useAuthStore()
  const router = useRouter()
  const { user, token, isGuest, sessionReady, isLoggedIn } = storeToRefs(store)

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
    const res = await apiFetch<AuthResponse>('/auth/login', { method: 'POST', body: { email, password } })
    store.token = res.access_token; store.user = res.user; store.isGuest = false
    router.push('/home')
  }

  async function register(payload: RegisterPayload): Promise<void> {
    const res = await apiFetch<AuthResponse>('/auth/register', { method: 'POST', body: payload })
    store.token = res.access_token; store.user = res.user; store.isGuest = false
    router.push('/home')
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
    const redirectUri = `${window.location.origin}/auth/callback`
    window.location.href = `${import.meta.env.VITE_API_URL}/auth/google?redirect_uri=${encodeURIComponent(redirectUri)}`
  }

  async function handleOAuthCallback(oauthToken: string): Promise<void> {
    store.token = oauthToken; store.isGuest = false
    const res = await apiFetch<{ user: User }>('/auth/me')
    store.user = res.user
    router.push('/home')
  }

  async function loginAsGuest(): Promise<void> {
    store.isGuest = true; router.push('/home')
  }

  async function logout(): Promise<void> {
    const { unsubscribeOnLogout } = useNotifications()
    await unsubscribeOnLogout()
    try { await apiFetch('/auth/logout', { method: 'POST' }) } catch {}
    store.token = null; store.user = null; store.isGuest = false
    const { success } = useToast()
    success('Đã đăng xuất thành công')
    router.push('/auth/login')
  }

  return { user, token, isLoggedIn, isGuest, sessionReady, login, register, forgotPassword, resetPassword, loginWithGoogle, handleOAuthCallback, loginAsGuest, logout, extractError }
}
