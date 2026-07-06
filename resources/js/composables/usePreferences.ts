import { ref, computed } from 'vue'
import { apiFetch } from '@/utils/api'
import type { PreferenceKind, UserPreference } from '@/types/preference'

// State dùng chung giữa các nơi gọi usePreferences()
const preferences = ref<UserPreference[]>([])
const loading     = ref(false)
const error       = ref<string | null>(null)
let loaded = false

export function usePreferences() {
  async function fetchAll(force = false): Promise<void> {
    if (loaded && !force) return
    loading.value = true
    error.value   = null
    try {
      const res = await apiFetch<{ preferences: UserPreference[]; limit: number }>('/preferences')
      preferences.value = res.preferences
      loaded = true
    } catch (e: any) {
      error.value = e?.message ?? 'Không tải được danh sách sở thích'
    } finally {
      loading.value = false
    }
  }

  async function add(kind: PreferenceKind, label: string): Promise<boolean> {
    const trimmed = label.trim()
    if (!trimmed) return false
    error.value = null
    try {
      const res = await apiFetch<{ preference: UserPreference }>('/preferences', {
        method: 'POST',
        body:   { kind, label: trimmed },
      })
      // Server có thể trả bản ghi đã tồn tại (được cập nhật) → upsert theo id
      const idx = preferences.value.findIndex(p => p.id === res.preference.id)
      if (idx >= 0) preferences.value[idx] = res.preference
      else preferences.value.push(res.preference)
      return true
    } catch (e: any) {
      error.value = e?.data?.message ?? e?.message ?? 'Không thêm được mục này'
      return false
    }
  }

  async function remove(id: number): Promise<void> {
    const prev = preferences.value
    preferences.value = preferences.value.filter(p => p.id !== id)   // optimistic
    try {
      await apiFetch(`/preferences/${id}`, { method: 'DELETE' })
    } catch {
      preferences.value = prev                                        // rollback
    }
  }

  const byKind = computed<Record<PreferenceKind, UserPreference[]>>(() => {
    const groups: Record<PreferenceKind, UserPreference[]> = {
      allergy: [], diet: [], dislike: [], like: [], habit: [],
    }
    for (const p of preferences.value) groups[p.kind]?.push(p)
    return groups
  })

  return { preferences, loading, error, fetchAll, add, remove, byKind }
}
