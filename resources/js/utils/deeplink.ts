import { router } from '@/router'

// Whitelist các path SPA hợp lệ — chống open-redirect tới URL ngoài.
const ALLOWED_PREFIXES = ['/home', '/scan', '/history', '/plan', '/chat', '/profile', '/result', '/meal-picker']

/** Trả về path an toàn (đã whitelist); fallback '/home' nếu không hợp lệ. */
export function safePath(raw?: string | null): string {
  if (!raw || !raw.startsWith('/')) return '/home'
  return ALLOWED_PREFIXES.some(p => raw === p || raw.startsWith(p + '?') || raw.startsWith(p + '/'))
    ? raw
    : '/home'
}

/**
 * Điều hướng client-side tới path. Chỉ cần push — router guard sẽ tự lưu
 * `pending_redirect` nếu route cần auth và mở lại sau khi đăng nhập.
 */
export function goWithAuth(path: string): void {
  router.push(safePath(path))
}
