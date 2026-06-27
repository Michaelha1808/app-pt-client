// App Badging API — hiển thị số chưa đọc trên icon PWA (iOS/iPadOS 16.4+, Android
// Chrome, macOS, Windows). No-op khi trình duyệt không hỗ trợ hoặc app mở trong
// tab thường thay vì đã cài vào home screen.

interface BadgeNavigator {
  setAppBadge?: (count?: number) => Promise<void>
  clearAppBadge?: () => Promise<void>
}

export function setAppBadge(count: number): void {
  const nav = navigator as BadgeNavigator
  if (count > 0) nav.setAppBadge?.(count).catch(() => {})
  else nav.clearAppBadge?.().catch(() => {})
}

export function clearAppBadge(): void {
  ;(navigator as BadgeNavigator).clearAppBadge?.().catch(() => {})
}
