/// <reference lib="webworker" />
import { cleanupOutdatedCaches, precacheAndRoute } from 'workbox-precaching'
import { registerRoute } from 'workbox-routing'
import { CacheFirst, NetworkFirst } from 'workbox-strategies'
import { CacheableResponsePlugin } from 'workbox-cacheable-response'
import { ExpirationPlugin } from 'workbox-expiration'
import { initializeApp } from 'firebase/app'
import { getMessaging, onBackgroundMessage } from 'firebase/messaging/sw'

declare let self: ServiceWorkerGlobalScope

// ── Workbox precaching ─────────────────────────────────────────────────────
cleanupOutdatedCaches()
precacheAndRoute(self.__WB_MANIFEST)

// ── Runtime caching ────────────────────────────────────────────────────────
registerRoute(
  ({ url }) => /^https:\/\/fonts\.(bunny|google)apis\.com\/.*/i.test(url.href),
  new CacheFirst({
    cacheName: 'google-fonts-cache',
    plugins: [
      new CacheableResponsePlugin({ statuses: [0, 200] }),
      new ExpirationPlugin({ maxEntries: 10, maxAgeSeconds: 60 * 60 * 24 * 365 }),
    ],
  }),
)

registerRoute(
  ({ url }) => url.pathname.startsWith('/api/'),
  new NetworkFirst({
    cacheName: 'api-cache',
    networkTimeoutSeconds: 5,
    plugins: [
      new CacheableResponsePlugin({ statuses: [0, 200] }),
      new ExpirationPlugin({ maxEntries: 100, maxAgeSeconds: 60 * 5 }),
    ],
  }),
)

// ── Firebase Cloud Messaging (background push) ─────────────────────────────
const firebaseApp = initializeApp({
  apiKey: import.meta.env.VITE_FIREBASE_API_KEY,
  authDomain: import.meta.env.VITE_FIREBASE_AUTH_DOMAIN,
  projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID,
  storageBucket: import.meta.env.VITE_FIREBASE_STORAGE_BUCKET,
  messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID,
  appId: import.meta.env.VITE_FIREBASE_APP_ID,
})

const messaging = getMessaging(firebaseApp)

onBackgroundMessage(messaging, (payload) => {
  const { title = 'CaloEye', body, icon } = payload.notification ?? {}
  self.registration.showNotification(title, {
    body,
    icon: icon ?? '/logo/caloreye_icon_192.png',
    badge: '/logo/caloreye_icon_192.png',
    data: payload.data,
  })
})

// ── Notification click → deep link ─────────────────────────────────────────
const ALLOWED_PREFIXES = ['/home', '/scan', '/history', '/plan', '/chat', '/profile', '/result', '/meal-picker']

function safePath(raw?: string): string {
  if (!raw || !raw.startsWith('/')) return '/home'
  return ALLOWED_PREFIXES.some(p => raw === p || raw.startsWith(p + '?') || raw.startsWith(p + '/'))
    ? raw
    : '/home'
}

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const data = (event.notification.data ?? {}) as Record<string, string>
  const path = safePath(data.url)

  event.waitUntil((async () => {
    const allClients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })

    // 1) Có cửa sổ PWA đang mở (cùng origin) → focus + nhờ SPA điều hướng client-side
    for (const client of allClients) {
      if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
        await client.focus()
        client.postMessage({ source: 'caloeye-sw', type: 'NAVIGATE', path, data })
        return
      }
    }

    // 2) Chưa mở → mở cửa sổ mới thẳng vào path (cold start)
    if (self.clients.openWindow) await self.clients.openWindow(path)
  })())
})
