import { ref, watch } from 'vue'

/**
 * Chủ đề màu toàn app, lưu ở máy (localStorage) — không cần đăng nhập.
 *  - 'green' : bảng màu CaloEye xanh gốc (MẶC ĐỊNH)
 *  - 'matcha': bảng màu Soft Matcha (tuỳ chọn mới)
 *
 * Cơ chế: Tailwind v4 phát @theme token ra CSS variable trên :root (giá trị matcha).
 * Mặc định 'green' → gắn [data-theme="green"] lên <html>, main.css override
 * toàn bộ token sang palette xanh → mọi class token tự đổi. Giữ nguyên bố cục.
 */
export type ThemeName = 'matcha' | 'green'

const KEY = 'ui_theme'

function readTheme(): ThemeName {
  const v = (typeof localStorage !== 'undefined' && localStorage.getItem(KEY)) as ThemeName | null
  return v === 'matcha' ? 'matcha' : 'green' // mặc định xanh CaloEye gốc
}

function applyTheme(t: ThemeName) {
  if (typeof document === 'undefined') return
  const root = document.documentElement
  if (t === 'green') root.setAttribute('data-theme', 'green')
  else root.removeAttribute('data-theme') // matcha = mặc định
  // Đồng bộ màu thanh trạng thái (PWA)
  const meta = document.querySelector('meta[name="theme-color"]')
  if (meta) meta.setAttribute('content', t === 'green' ? '#18A874' : '#7c9a70')
}

// Singleton — dùng chung mọi nơi gọi useTheme()
const theme = ref<ThemeName>(readTheme())
applyTheme(theme.value) // áp ngay khi module nạp (tránh nháy màu)

watch(theme, (t) => {
  try { localStorage.setItem(KEY, t) } catch {}
  applyTheme(t)
})

export function useTheme() {
  return { theme }
}
