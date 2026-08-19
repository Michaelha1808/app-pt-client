<script setup lang="ts">
import FoodEditSheet from '@/components/food/FoodEditSheet.vue'
import type { FoodEditValues } from '@/components/food/FoodEditSheet.vue'
import QuantityStepper from '@/components/food/QuantityStepper.vue'
import { dishCalories, stepFor, minFor } from '@/utils/nutrition'
import type { DishPick } from '@/types/food'

const props = defineProps<{ dish: DishPick }>()
const emit = defineEmits<{
  'update:selected': [boolean]
  'update:quantity': [number]
  'update:calories': [number]
  'update:food_name': [string]
  'update:serving': [string]
  'update:protein': [number]
  'update:carbs': [number]
  'update:fat': [number]
  'update:sodium': [number]
}>()

const lowConfidence = computed(() => props.dish.confidence < 0.5)

// Sửa thông tin món qua popup (thay vì ô nhập nhỏ chèn trực tiếp vào hàng — khó bấm trúng
// trên di động, và trước đây không sửa được khẩu phần/macro). Số lượng vẫn dùng stepper +/-
// bên ngoài popup vì đã là UX tốt sẵn, không phải phần user phàn nàn khó thao tác.
const sheetOpen = ref(false)

function handleSave(values: FoodEditValues) {
  if (values.food_name !== props.dish.food_name) emit('update:food_name', values.food_name)
  if (values.serving !== props.dish.serving) emit('update:serving', values.serving)
  if (values.calories !== props.dish.calories) emit('update:calories', values.calories)
  if (values.protein !== props.dish.protein) emit('update:protein', values.protein)
  if (values.carbs !== props.dish.carbs) emit('update:carbs', values.carbs)
  if (values.fat !== props.dish.fat) emit('update:fat', values.fat)
  if (values.sodium !== props.dish.sodium) emit('update:sodium', values.sodium)
}
</script>

<template>
  <div class="flex items-center gap-3 px-4 py-3.5">
    <!-- Checkbox -->
    <button
      type="button"
      class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 ios-press border-2 transition-colors"
      :class="dish.selected ? 'bg-calor-green border-calor-green' : 'bg-white border-ios-gray3'"
      @click="emit('update:selected', !dish.selected)"
    >
      <svg v-if="dish.selected" viewBox="0 0 24 24" class="w-4 h-4" fill="white">
        <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
      </svg>
    </button>

    <!-- Info — bấm để mở popup sửa (tên, khẩu phần/khối lượng, calo, macro cho 1 đơn vị) -->
    <button
      type="button"
      class="flex-1 min-w-0 text-left ios-press"
      :class="dish.selected ? '' : 'opacity-50'"
      @click="sheetOpen = true"
    >
      <div class="flex items-center gap-1.5">
        <span class="text-[15px] font-medium text-black truncate">{{ dish.food_name }}</span>
        <svg viewBox="0 0 24 24" class="w-3 h-3 text-ios-gray3 flex-shrink-0" fill="currentColor">
          <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a.996.996 0 0 0 0-1.41l-2.34-2.34a.996.996 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>
        </svg>
        <span
          v-if="dish.source === 'catalog'"
          class="flex-shrink-0 text-[10px] text-calor-green bg-calor-green/10 px-1.5 py-0.5 rounded-full font-medium"
        >📚 Thư viện</span>
        <span
          v-else-if="lowConfidence"
          class="flex-shrink-0 text-[10px] text-ios-orange bg-ios-orange/10 px-1.5 py-0.5 rounded-full font-medium"
        >AI chưa chắc</span>
      </div>
      <span class="block text-[12px] text-ios-gray mt-0.5">{{ dish.serving }} · {{ dishCalories(dish, dish.quantity) }} kcal</span>
    </button>

    <!-- Quantity -->
    <QuantityStepper
      :model-value="dish.quantity"
      :step="stepFor(dish.unit_type)"
      :min="minFor(dish.unit_type)"
      :unit-label="dish.unit_label"
      :disabled="!dish.selected"
      @update:model-value="emit('update:quantity', $event)"
    />
  </div>

  <FoodEditSheet
    v-model:open="sheetOpen"
    :initial="{
      food_name: dish.food_name,
      serving:   dish.serving,
      calories:  dish.calories,
      protein:   dish.protein,
      carbs:     dish.carbs,
      fat:       dish.fat,
      sodium:    dish.sodium,
    }"
    calories-label="Calo / 1 đơn vị (kcal) — số lượng chỉnh bằng nút +/- ngoài popup"
    @save="handleSave"
  />
</template>
