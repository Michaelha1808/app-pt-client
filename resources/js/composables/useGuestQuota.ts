import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'

export type QuotaAction = 'scan' | 'chat'

/** Giới hạn miễn phí mỗi NGÀY cho tư cách khách */
const LIMITS: Record<QuotaAction, number> = { scan: 1, chat: 1 }

const STORAGE_KEY = 'guest_quota'

interface QuotaState {
  date: string
  scan: number
  chat: number
}

function todayKey(): string {
  const d = new Date()
  return `${d.getFullYear()}-${d.getMonth() + 1}-${d.getDate()}`
}

function load(): QuotaState {
  const fresh: QuotaState = { date: todayKey(), scan: 0, chat: 0 }
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return fresh
    const parsed = JSON.parse(raw) as QuotaState
    // Sang ngày mới → reset quota
    if (parsed.date !== todayKey()) return fresh
    return { date: parsed.date, scan: parsed.scan ?? 0, chat: parsed.chat ?? 0 }
  } catch {
    return fresh
  }
}

// State dùng chung toàn app
const state = ref<QuotaState>(load())

function persist() {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state.value))
  } catch {
    // bỏ qua nếu localStorage không khả dụng
  }
}

function syncDate() {
  if (state.value.date !== todayKey()) {
    state.value = { date: todayKey(), scan: 0, chat: 0 }
    persist()
  }
}

export function useGuestQuota() {
  /** Khách còn lượt cho action không? Người dùng đã đăng nhập → luôn true (không giới hạn). */
  function canUse(action: QuotaAction): boolean {
    const store = useAuthStore()
    if (!store.isGuest) return true
    syncDate()
    return state.value[action] < LIMITS[action]
  }

  /** Ghi nhận đã dùng 1 lượt (chỉ áp dụng cho khách). */
  function increment(action: QuotaAction) {
    const store = useAuthStore()
    if (!store.isGuest) return
    syncDate()
    state.value[action] += 1
    persist()
  }

  function remaining(action: QuotaAction): number {
    syncDate()
    return Math.max(0, LIMITS[action] - state.value[action])
  }

  function limit(action: QuotaAction): number {
    return LIMITS[action]
  }

  return { canUse, increment, remaining, limit }
}
