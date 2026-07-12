import { ref } from 'vue'
import { apiFetch } from '@/utils/api'

export interface PublicConfig {
  features: {
    registration_open: boolean
    guest_mode_enabled: boolean
    maintenance_mode: boolean
  }
  oauth: {
    google_enabled: boolean
    facebook_enabled: boolean
  }
  ai: {
    food_analysis_enabled: boolean
    chat_enabled: boolean
  }
}

// Cache module-level với TTL: flags admin có thể đổi runtime nên không giữ vĩnh viễn
let cached: PublicConfig | null = null
let fetchedAt = 0
const TTL_MS = 60_000

/**
 * Feature flags public do admin cấu hình runtime (GET /config, không cần auth).
 * `config` là null cho tới khi load xong — FE nên mặc định HIỆN các nút và chỉ
 * ẩn khi flag trả về false, tránh nháy UI. Cache 60s rồi tự refetch.
 */
export function usePublicConfig() {
  const config = ref<PublicConfig | null>(cached)

  async function loadPublicConfig() {
    if (!cached || Date.now() - fetchedAt > TTL_MS) {
      try {
        cached = await apiFetch<PublicConfig>('/config')
        fetchedAt = Date.now()
      } catch {
        // lỗi mạng → giữ cache cũ nếu có (null = hiện đủ nút), không chặn trang
      }
    }
    config.value = cached
  }

  /** Bỏ qua TTL, gọi lại /config ngay (dùng khi cần trạng thái mới nhất). */
  async function refresh() {
    fetchedAt = 0
    await loadPublicConfig()
  }

  const flag = (get: (c: PublicConfig) => boolean) =>
    config.value ? get(config.value) : true

  return { config, loadPublicConfig, refresh, flag }
}
