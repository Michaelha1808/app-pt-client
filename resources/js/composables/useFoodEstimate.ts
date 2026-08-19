import { ref } from 'vue'
import { apiFetch } from '@/utils/api'
import type { NutritionEstimate } from '@/types/food'

/**
 * Ước tính lại calo + macro khi user sửa tên món/khẩu phần sau lúc AI nhận diện.
 *
 * Trước đây sửa "Phở bò" → "Bánh xèo tôm thịt" chỉ đổi chữ hiển thị, calo/macro vẫn giữ số
 * của Phở bò → nhật ký lưu sai. Dùng chung cho cả Result.vue (1 món) và MealPicker.vue
 * (nhiều món — truyền unitLabel để ước tính cho 1 đơn vị, nhân với stepper số lượng).
 */
export function useFoodEstimate() {
  const estimating = ref(false)
  const error      = ref<string | null>(null)

  async function estimate(
    foodName: string,
    serving?: string | null,
    unitLabel?: string | null,
  ): Promise<NutritionEstimate | null> {
    if (!foodName.trim()) return null

    estimating.value = true
    error.value      = null

    try {
      const est = await apiFetch<NutritionEstimate>('/food/estimate', {
        method: 'POST',
        body: {
          food_name:  foodName,
          serving:    serving   ?? null,
          unit_label: unitLabel ?? null,
        },
      })
      // calories = 0 nghĩa là AI không nhận ra đây là món ăn → giữ nguyên số user đang có,
      // không ghi đè thành 0 (sẽ làm hỏng dữ liệu nhật ký).
      return est.calories > 0 ? est : null
    } catch (e: any) {
      if (e?.message !== 'auth:session_expired') {
        error.value = e?.data?.message ?? e?.response?._data?.message
          ?? 'Không ước tính được dinh dưỡng cho món này.'
      }
      return null
    } finally {
      estimating.value = false
    }
  }

  return { estimating, error, estimate }
}
