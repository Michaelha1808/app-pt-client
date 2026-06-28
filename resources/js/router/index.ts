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
  { path: '/meal-picker', component: () => import('@/pages/MealPicker.vue'), meta: { layout: 'app', middleware: 'auth' } },
  { path: '/plan', component: () => import('@/pages/MealPlan.vue'), meta: { layout: 'app', middleware: 'auth-strict' } },
  { path: '/profile', component: () => import('@/pages/Profile.vue'), meta: { layout: 'app', middleware: 'auth' } },
  { path: '/activities', component: () => import('@/pages/Activities.vue'), meta: { layout: 'app', middleware: 'auth-strict' } },
  { path: '/integrations/callback', component: () => import('@/pages/integrations/Callback.vue'), meta: { layout: 'app', middleware: 'auth-strict' } },
  { path: '/profile/edit', component: () => import('@/pages/profile/Edit.vue'), meta: { layout: 'app', middleware: 'auth-strict' } },
  { path: '/profile/change-password', component: () => import('@/pages/profile/ChangePassword.vue'), meta: { layout: 'app', middleware: 'auth-strict' } },
  { path: '/settings/notifications', component: () => import('@/pages/settings/Notifications.vue'), meta: { layout: 'app', middleware: 'auth-strict' } },

  // ── Admin ──
  { path: '/admin/login', component: () => import('@/pages/admin/Login.vue'), meta: { layout: false } },
  { path: '/admin', component: () => import('@/pages/admin/Dashboard.vue'), meta: { layout: 'admin', middleware: 'admin' } },
  { path: '/admin/users', component: () => import('@/pages/admin/Users.vue'), meta: { layout: 'admin', middleware: 'admin' } },
  { path: '/admin/users/:id', component: () => import('@/pages/admin/UserDetail.vue'), meta: { layout: 'admin', middleware: 'admin' } },
  { path: '/admin/notifications', component: () => import('@/pages/admin/Notifications.vue'), meta: { layout: 'admin', middleware: 'admin' } },
  { path: '/admin/settings', component: () => import('@/pages/admin/Settings.vue'), meta: { layout: 'admin', middleware: 'admin' } },
  { path: '/admin/audit-logs', component: () => import('@/pages/admin/AuditLogs.vue'), meta: { layout: 'admin', middleware: 'admin' } },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
})

let sessionInitialized = false

const PENDING_KEY = 'pending_redirect'

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!sessionInitialized) {
    sessionInitialized = true
    await restoreSession()
  }
  const mw = to.meta.middleware as string | undefined
  if (mw === 'guest' && auth.isLoggedIn) return '/home'

  // Admin: phải đăng nhập thật + có role admin → dùng màn login admin riêng
  if (mw === 'admin') {
    if (!auth.isLoggedIn || !auth.isAdmin) return '/admin/login'
    return
  }

  const needAuth =
    (mw === 'auth' && !auth.isLoggedIn && !auth.isGuest) ||
    (mw === 'auth-strict' && !auth.isLoggedIn)

  if (needAuth) {
    // Lưu đích đang muốn vào (trừ trang auth) để mở lại sau khi đăng nhập
    if (!to.path.startsWith('/auth')) {
      sessionStorage.setItem(PENDING_KEY, to.fullPath)
    }
    return '/auth/login'
  }
})

export default router
