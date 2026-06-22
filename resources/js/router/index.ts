import { createRouter, createWebHistory } from 'vue-router'
import type { RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { restoreSession } from '@/utils/session'

// Lazy-load pages
const routes: RouteRecordRaw[] = [
  { path: '/', component: () => import('@/pages/Index.vue'), meta: { layout: false } },
  { path: '/auth/login', component: () => import('@/pages/auth/Login.vue'), meta: { layout: 'auth', middleware: 'guest' } },
  { path: '/auth/register', component: () => import('@/pages/auth/Register.vue'), meta: { layout: 'auth', middleware: 'guest' } },
  { path: '/auth/forgot-password', component: () => import('@/pages/auth/ForgotPassword.vue'), meta: { layout: 'auth', middleware: 'guest' } },
  { path: '/auth/reset-password', component: () => import('@/pages/auth/ResetPassword.vue'), meta: { layout: 'auth' } },
  { path: '/auth/callback', component: () => import('@/pages/auth/Callback.vue'), meta: { layout: 'auth' } },
  { path: '/home', component: () => import('@/pages/Home.vue'), meta: { layout: 'app', middleware: 'auth' } },
  { path: '/chat', component: () => import('@/pages/Chat.vue'), meta: { layout: 'app', middleware: 'auth' } },
  { path: '/history', component: () => import('@/pages/History.vue'), meta: { layout: 'app', middleware: 'auth' } },
  { path: '/scan', component: () => import('@/pages/Scan.vue'), meta: { layout: 'app', middleware: 'auth' } },
  { path: '/result', component: () => import('@/pages/Result.vue'), meta: { layout: 'app', middleware: 'auth' } },
  { path: '/profile', component: () => import('@/pages/Profile.vue'), meta: { layout: 'app', middleware: 'auth' } },
  { path: '/profile/edit', component: () => import('@/pages/profile/Edit.vue'), meta: { layout: 'app', middleware: 'auth-strict' } },
  { path: '/profile/change-password', component: () => import('@/pages/profile/ChangePassword.vue'), meta: { layout: 'app', middleware: 'auth-strict' } },
  { path: '/settings/notifications', component: () => import('@/pages/settings/Notifications.vue'), meta: { layout: 'app', middleware: 'auth-strict' } },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
})

let sessionInitialized = false

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!sessionInitialized) {
    sessionInitialized = true
    await restoreSession()
  }
  const mw = to.meta.middleware as string | undefined
  if (mw === 'guest' && auth.isLoggedIn) return '/home'
  if (mw === 'auth' && !auth.isLoggedIn && !auth.isGuest) return '/auth/login'
  if (mw === 'auth-strict' && !auth.isLoggedIn) return '/auth/login'
})

export default router
