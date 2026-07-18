import { ref, computed, watch } from 'vue'

/**
 * Tuỳ chọn hiển thị lưu ở máy (localStorage) — không cần đăng nhập:
 *  - fontScale: cỡ chữ toàn app (áp bằng `zoom` ở AppLayout)
 *  - floatingChar: bật/tắt nhân vật AVO bay
 */
export type FontScale = 'normal' | 'large' | 'xlarge'

const FONT_KEY  = 'ui_font_scale'
const FLOAT_KEY = 'ui_floating_char'

const ZOOM: Record<FontScale, number> = {
  normal: 1,
  large:  1.1,
  xlarge: 1.2,
}

function readFontScale(): FontScale {
  const v = (typeof localStorage !== 'undefined' && localStorage.getItem(FONT_KEY)) as FontScale | null
  return v === 'normal' || v === 'large' || v === 'xlarge' ? v : 'large'   // mặc định to hơn chút
}

function readFloating(): boolean {
  if (typeof localStorage === 'undefined') return true
  return localStorage.getItem(FLOAT_KEY) !== '0'   // mặc định bật
}

// Singleton — dùng chung mọi nơi gọi useUiSettings()
const fontScale    = ref<FontScale>(readFontScale())
const floatingChar = ref<boolean>(readFloating())

watch(fontScale, (v) => {
  try { localStorage.setItem(FONT_KEY, v) } catch {}
})
watch(floatingChar, (v) => {
  try { localStorage.setItem(FLOAT_KEY, v ? '1' : '0') } catch {}
})

const fontZoom = computed(() => ZOOM[fontScale.value] ?? 1)

export function useUiSettings() {
  return { fontScale, floatingChar, fontZoom }
}
