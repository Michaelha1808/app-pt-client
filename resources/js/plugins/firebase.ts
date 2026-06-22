import { initializeApp, getApps } from 'firebase/app'
import { getMessaging, getToken, onMessage, type Messaging } from 'firebase/messaging'

const firebaseConfig = {
  apiKey: import.meta.env.VITE_FIREBASE_API_KEY,
  authDomain: import.meta.env.VITE_FIREBASE_AUTH_DOMAIN,
  projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID,
  storageBucket: import.meta.env.VITE_FIREBASE_STORAGE_BUCKET,
  messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID,
  appId: import.meta.env.VITE_FIREBASE_APP_ID,
}

export const firebaseApp = getApps().length ? getApps()[0] : initializeApp(firebaseConfig)

// Lazy-init messaging (fails outside browser or if config missing)
let _messaging: Messaging | null = null
export function getFirebaseMessaging(): Messaging | null {
  if (!import.meta.env.VITE_FIREBASE_API_KEY) return null
  if (!('Notification' in window)) return null
  if (!_messaging) _messaging = getMessaging(firebaseApp)
  return _messaging
}

export const VAPID_KEY = import.meta.env.VITE_FIREBASE_VAPID_KEY as string

export { getToken, onMessage }
