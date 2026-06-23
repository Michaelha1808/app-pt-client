import { ref, computed } from 'vue'
import { useAuth } from '@/composables/useAuth'
import { apiFetch } from '@/utils/api'
import type { User, UpdateProfilePayload } from '@/types/auth'

export function useProfile() {
  const { user, extractError } = useAuth()

  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')

  const age = computed(() => {
    if (!user.value?.birth_year) return null
    return new Date().getFullYear() - user.value.birth_year
  })

  const bmi = computed((): string | null => {
    const h = user.value?.height_cm
    const w = user.value?.weight_kg
    if (!h || !w) return null
    return (w / ((h / 100) ** 2)).toFixed(1)
  })

  const bmr = computed((): number | null => {
    const { height_cm, weight_kg, birth_year, gender } = user.value ?? {}
    if (!height_cm || !weight_kg || !birth_year) return null
    const a = new Date().getFullYear() - birth_year
    const base = 10 * weight_kg + 6.25 * height_cm - 5 * a
    return Math.round(gender === 'female' ? base - 161 : base + 5)
  })

  const bmiLabel = computed((): { text: string; color: string } | null => {
    if (!bmi.value) return null
    const b = parseFloat(bmi.value)
    if (b < 18.5) return { text: 'Gầy', color: '#32ADE6' }
    if (b < 25)   return { text: 'Bình thường', color: '#34C759' }
    if (b < 30)   return { text: 'Thừa cân', color: '#FF9500' }
    return { text: 'Béo phì', color: '#FF3B30' }
  })

  async function fetchProfile(): Promise<void> {
    loading.value = true
    error.value = ''
    try {
      const res = await apiFetch<{ user: User }>('/user/profile')
      user.value = res.user
    } catch (err) {
      error.value = extractError(err)
    } finally {
      loading.value = false
    }
  }

  async function saveProfile(payload: UpdateProfilePayload): Promise<boolean> {
    saving.value = true
    error.value = ''
    try {
      const res = await apiFetch<{ user: User }>('/user/profile', {
        method: 'PATCH',
        body: payload,
      })
      user.value = res.user
      return true
    } catch (err) {
      error.value = extractError(err)
      return false
    } finally {
      saving.value = false
    }
  }

  async function uploadAvatar(file: File): Promise<string | null> {
    error.value = ''
    const body = new FormData()
    body.append('avatar', file)
    try {
      const res = await apiFetch<{ avatar_url: string }>('/user/avatar', {
        method: 'POST',
        body,
      })
      if (user.value) user.value.avatar_url = res.avatar_url
      return res.avatar_url
    } catch (err) {
      error.value = extractError(err)
      return null
    }
  }

  async function deleteAvatar(): Promise<boolean> {
    error.value = ''
    try {
      await apiFetch('/user/avatar', { method: 'DELETE' })
      if (user.value) user.value.avatar_url = null
      return true
    } catch (err) {
      error.value = extractError(err)
      return false
    }
  }

  return {
    loading,
    saving,
    error,
    age,
    bmi,
    bmr,
    bmiLabel,
    fetchProfile,
    saveProfile,
    uploadAvatar,
    deleteAvatar,
  }
}
