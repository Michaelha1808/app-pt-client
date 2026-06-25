import { goWithAuth } from '@/utils/deeplink'

/**
 * Lắng nghe message từ Service Worker (notificationclick) → điều hướng SPA.
 * Gọi 1 lần sau khi router đã mount (trong app.ts).
 */
export function initNotificationNav(): void {
  if (!('serviceWorker' in navigator)) return
  navigator.serviceWorker.addEventListener('message', (event) => {
    const msg = event.data
    if (msg?.source === 'caloeye-sw' && msg.type === 'NAVIGATE' && typeof msg.path === 'string') {
      goWithAuth(msg.path) // tự xử lý auth + pending qua router guard
    }
  })
}
