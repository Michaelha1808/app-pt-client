import { apiFetch } from '@/utils/api'
import { getFirebaseMessaging, getToken, onMessage, VAPID_KEY } from '@/plugins/firebase'

export interface NotificationSettings {
  morning: { enabled: boolean; time: string }
  midday:  { enabled: boolean }
  evening: { enabled: boolean; time: string }
  email_reengagement: { enabled: boolean }
}

const settings   = ref<NotificationSettings | null>(null)
const loading    = ref(false)
const permission = ref<NotificationPermission>(
  typeof Notification !== 'undefined' ? Notification.permission : 'default',
)

const FCM_TOKEN_KEY = 'fcm_token'

let _saveTimer: ReturnType<typeof setTimeout> | null = null

export function useNotifications() {
  async function fetchSettings() {
    loading.value = true
    try {
      settings.value = await apiFetch<NotificationSettings>('/notifications/settings')
    } finally {
      loading.value = false
    }
  }

  // Gọi ngay sau khi user tap (iOS yêu cầu user gesture)
  async function requestPermission(): Promise<boolean> {
    if (typeof Notification === 'undefined') return false

    const result = await Notification.requestPermission()
    permission.value = result

    if (result === 'granted') {
      await _subscribeToBackend()
    }
    return result === 'granted'
  }

  // Gọi khi app khởi động (nếu đã có permission thì subscribe lại — backend dùng updateOrCreate)
  async function initPush() {
    if (typeof Notification === 'undefined') return
    permission.value = Notification.permission
    if (Notification.permission === 'granted') {
      await _subscribeToBackend()
    }

    const messaging = getFirebaseMessaging()
    if (!messaging) return

    onMessage(messaging, (payload) => {
      const { title = 'CaloEye', body } = payload.notification ?? {}
      if (Notification.permission === 'granted') {
        new Notification(title, { body, icon: '/logo/caloreye_icon_192.png' })
      }
    })
  }

  async function unsubscribeOnLogout() {
    const token = localStorage.getItem(FCM_TOKEN_KEY)
    if (!token) return
    try {
      await apiFetch('/notifications/subscribe', { method: 'DELETE', body: { fcm_token: token } })
    } catch {}
    localStorage.removeItem(FCM_TOKEN_KEY)
  }

  async function unsubscribe(fcmToken: string) {
    try {
      await apiFetch('/notifications/subscribe', { method: 'DELETE', body: { fcm_token: fcmToken } })
      localStorage.removeItem(FCM_TOKEN_KEY)
    } catch {}
  }

  // Debounce saves 400ms để không spam API khi toggle nhanh
  function updateSetting(patch: Partial<NotificationSettings>) {
    if (!settings.value) return
    // Optimistic update
    settings.value = deepMerge(settings.value, patch)

    if (_saveTimer) clearTimeout(_saveTimer)
    _saveTimer = setTimeout(() => {
      apiFetch('/notifications/settings', { method: 'PUT', body: flattenPatch(patch) }).catch(() => {})
    }, 400)
  }

  return { settings, loading, permission, fetchSettings, initPush, requestPermission, unsubscribe, unsubscribeOnLogout, updateSetting }
}

// ── Helpers ────────────────────────────────────────────────────────────────────

async function _subscribeToBackend() {
  const messaging = getFirebaseMessaging()
  if (!messaging) return
  try {
    const swReg = await navigator.serviceWorker.ready
    const token = await getToken(messaging, { vapidKey: VAPID_KEY, serviceWorkerRegistration: swReg })
    if (!token) return

    localStorage.setItem(FCM_TOKEN_KEY, token)

    const ua = navigator.userAgent
    const deviceType = /iPad|iPhone|iPod/.test(ua) ? 'ios'
      : /Android/.test(ua) ? 'android'
      : 'web'

    await apiFetch('/notifications/subscribe', {
      method: 'POST',
      body: { fcm_token: token, device_type: deviceType },
    })
  } catch {}
}

function deepMerge<T extends object>(base: T, patch: Partial<T>): T {
  const result = { ...base }
  for (const key in patch) {
    const v = patch[key]
    if (v !== null && typeof v === 'object' && !Array.isArray(v)) {
      result[key] = deepMerge(base[key] as any, v as any)
    } else if (v !== undefined) {
      result[key] = v as any
    }
  }
  return result
}

// NotificationSettings → flat object cho API (vd: { 'morning.enabled': true })
function flattenPatch(patch: Partial<NotificationSettings>): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  for (const section in patch) {
    const val = (patch as any)[section]
    if (typeof val === 'object') {
      for (const key in val) {
        out[`${section}.${key}`] = val[key]
      }
    }
  }
  return out
}
