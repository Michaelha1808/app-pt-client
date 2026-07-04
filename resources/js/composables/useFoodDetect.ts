import { ref } from 'vue'
import { apiFetch } from '@/utils/api'
import type { DetectResponse, DetectedDish } from '@/types/food'

export function useFoodDetect() {
  const dishes      = ref<DetectedDish[]>([])
  const loading     = ref(false)
  const error       = ref<string | null>(null)
  const detectionId = ref<number | null>(null)

  async function detect(opts: { image?: string | null; text?: string | null }): Promise<void> {
    dishes.value      = []
    detectionId.value = null
    error.value       = null
    loading.value     = true

    try {
      const res = await apiFetch<DetectResponse>('/food/detect', {
        method: 'POST',
        body: { image: opts.image ?? null, text: opts.text ?? null },
      })
      dishes.value      = res.dishes ?? []
      detectionId.value = res.detection_id ?? null
    } catch (e: any) {
      if (e?.message !== 'auth:session_expired') {
        const msg = e?.data?.message ?? e?.response?._data?.message
        error.value = msg ?? 'Không thể nhận diện món ăn. Vui lòng thử lại.'
      }
    } finally {
      loading.value = false
    }
  }

  return { dishes, detectionId, loading, error, detect }
}
