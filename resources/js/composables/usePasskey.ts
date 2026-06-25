import { ref } from 'vue'
import { apiFetch } from '@/utils/api'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types/auth'

/**
 * Đăng nhập / đăng ký bằng passkey (vân tay, Face ID) — WebAuthn verify phía server.
 *
 * - register(): sau khi đã đăng nhập, đăng ký thiết bị này làm passkey (discoverable).
 * - loginWithPasskey(): trên màn Login, quét vân tay → server xác minh → cấp phiên.
 *
 * `registered` (localStorage theo origin) chỉ để ẩn/hiện nút trên màn Login;
 * `enabled` phản ánh dữ liệu thật phía server cho user đang đăng nhập.
 */

const REGISTERED_KEY = 'passkey_registered'

const supported  = ref(false)
const enabled    = ref(false)
const registered = ref(localStorage.getItem(REGISTERED_KEY) === '1')

// ── Helpers base64url ↔ ArrayBuffer ────────────────────────────────────────
function bufToB64url(buf: ArrayBuffer): string {
  const bytes = new Uint8Array(buf)
  let s = ''
  for (const b of bytes) s += String.fromCharCode(b)
  return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}
function b64urlToBuf(b64: string): Uint8Array {
  const s = b64.replace(/-/g, '+').replace(/_/g, '/')
  const pad = s.length % 4 ? '='.repeat(4 - (s.length % 4)) : ''
  const bin = atob(s + pad)
  const out = new Uint8Array(bin.length)
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i)
  return out
}

export function usePasskey() {
  const store = useAuthStore()

  async function checkSupport(): Promise<boolean> {
    try {
      supported.value =
        !!window.PublicKeyCredential &&
        (await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable())
    } catch {
      supported.value = false
    }
    return supported.value
  }

  /** Lấy trạng thái passkey của user hiện tại (đã đăng nhập). */
  async function fetchStatus(): Promise<void> {
    try {
      const res = await apiFetch<{ enabled: boolean }>('/webauthn/status')
      enabled.value = res.enabled
    } catch {
      enabled.value = false
    }
  }

  /** Đăng ký passkey cho thiết bị này (yêu cầu đang đăng nhập). */
  async function register(): Promise<boolean> {
    if (!window.PublicKeyCredential) return false
    try {
      const opts = await apiFetch<any>('/webauthn/register/options', { method: 'POST' })
      const cred = (await navigator.credentials.create({
        publicKey: {
          challenge: b64urlToBuf(opts.challenge),
          rp: opts.rp,
          user: {
            id: b64urlToBuf(opts.user.id),
            name: opts.user.name,
            displayName: opts.user.displayName,
          },
          pubKeyCredParams: opts.pubKeyCredParams,
          authenticatorSelection: opts.authenticatorSelection,
          timeout: opts.timeout,
          attestation: opts.attestation,
          excludeCredentials: (opts.excludeCredentials ?? []).map((c: any) => ({
            type: c.type,
            id: b64urlToBuf(c.id),
          })),
        },
      })) as PublicKeyCredential | null
      if (!cred) return false

      const att = cred.response as AuthenticatorAttestationResponse
      await apiFetch('/webauthn/register/verify', {
        method: 'POST',
        body: {
          clientDataJSON: bufToB64url(att.clientDataJSON),
          attestationObject: bufToB64url(att.attestationObject),
        },
      })

      enabled.value = true
      registered.value = true
      localStorage.setItem(REGISTERED_KEY, '1')
      return true
    } catch {
      return false
    }
  }

  /** Tắt passkey: xoá toàn bộ ở server + cờ local. */
  async function disable(): Promise<void> {
    try { await apiFetch('/webauthn', { method: 'DELETE' }) } catch {}
    enabled.value = false
    registered.value = false
    localStorage.removeItem(REGISTERED_KEY)
  }

  /**
   * Đăng nhập bằng passkey trên màn Login. Thành công → set token + user vào store.
   * Ném lỗi (message tiếng Việt) nếu thất bại để UI hiển thị.
   */
  async function loginWithPasskey(): Promise<void> {
    if (!window.PublicKeyCredential) throw new Error('Thiết bị không hỗ trợ vân tay / Face ID.')

    const opts = await apiFetch<any>('/webauthn/login/options', { method: 'POST' })

    let assertion: PublicKeyCredential
    try {
      assertion = (await navigator.credentials.get({
        publicKey: {
          challenge: b64urlToBuf(opts.challenge),
          rpId: opts.rpId,
          allowCredentials: (opts.allowCredentials ?? []).map((c: any) => ({
            type: c.type,
            id: b64urlToBuf(c.id),
          })),
          userVerification: opts.userVerification,
          timeout: opts.timeout,
        },
      })) as PublicKeyCredential
    } catch {
      throw new Error('Xác thực vân tay / Face ID chưa thành công.')
    }
    if (!assertion) throw new Error('Xác thực vân tay / Face ID chưa thành công.')

    const resp = assertion.response as AuthenticatorAssertionResponse
    const res = await apiFetch<{ access_token: string }>('/webauthn/login/verify', {
      method: 'POST',
      body: {
        flowId: opts.flowId,
        id: assertion.id,
        clientDataJSON: bufToB64url(resp.clientDataJSON),
        authenticatorData: bufToB64url(resp.authenticatorData),
        signature: bufToB64url(resp.signature),
      },
    })

    store.token = res.access_token
    store.isGuest = false
    const me = await apiFetch<{ user: User }>('/auth/me')
    store.user = me.user
    registered.value = true
    localStorage.setItem(REGISTERED_KEY, '1')
  }

  return { supported, enabled, registered, checkSupport, fetchStatus, register, disable, loginWithPasskey }
}
