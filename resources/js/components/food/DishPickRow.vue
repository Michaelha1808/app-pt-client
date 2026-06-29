<script setup lang="ts">
import QuantityStepper from '@/components/food/QuantityStepper.vue'
import { dishCalories, stepFor, minFor } from '@/utils/nutrition'
import type { DishPick } from '@/types/food'

const props = defineProps<{ dish: DishPick }>()
const emit = defineEmits<{
  'update:selected': [boolean]
  'update:quantity': [number]
  'update:calories': [number]
  'update:food_name': [string]
}>()

const lowConfidence = computed(() => props.dish.confidence < 0.5)

const editing = ref(false)
const editVal = ref(0)

function startEdit() {
  editVal.value = props.dish.calories
  editing.value = true
}

function commitEdit() {
  const v = Math.max(0, Math.min(10000, Math.round(editVal.value || 0)))
  emit('update:calories', v)
  editing.value = false
}

// ── Sửa tên món (thu tín hiệu nhận sai tên cho dataset) ──
const nameEditing = ref(false)
const nameVal     = ref('')

function startNameEdit() {
  nameVal.value = props.dish.food_name
  nameEditing.value = true
}

function commitNameEdit() {
  const v = nameVal.value.trim().slice(0, 200)
  if (v) emit('update:food_name', v)
  nameEditing.value = false
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

    <!-- Info -->
    <div class="flex-1 min-w-0" :class="dish.selected ? '' : 'opacity-50'">
      <!-- Sửa tên món inline -->
      <div v-if="nameEditing" class="flex items-center gap-1.5">
        <input
          v-model="nameVal"
          type="text"
          maxlength="200"
          class="min-w-0 flex-1 px-2 py-0.5 text-[15px] font-medium border border-ios-blue rounded-md outline-none"
          @keydown.enter.prevent="commitNameEdit"
        />
        <button class="flex-shrink-0 text-[12px] text-ios-blue font-medium ios-press" @click="commitNameEdit">Xong</button>
      </div>
      <div v-else class="flex items-center gap-1.5">
        <button type="button" class="flex items-center gap-1 min-w-0 ios-press" @click="startNameEdit">
          <span class="text-[15px] font-medium text-black truncate">{{ dish.food_name }}</span>
          <svg viewBox="0 0 24 24" class="w-3 h-3 text-ios-gray3 flex-shrink-0" fill="currentColor">
            <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a.996.996 0 0 0 0-1.41l-2.34-2.34a.996.996 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>
          </svg>
        </button>
        <span
          v-if="dish.source === 'catalog'"
          class="flex-shrink-0 text-[10px] text-calor-green bg-calor-green/10 px-1.5 py-0.5 rounded-full font-medium"
        >📚 Thư viện</span>
        <span
          v-else-if="lowConfidence"
          class="flex-shrink-0 text-[10px] text-ios-orange bg-ios-orange/10 px-1.5 py-0.5 rounded-full font-medium"
        >AI chưa chắc</span>
      </div>

      <!-- Sửa calo/đơn vị inline -->
      <div v-if="editing" class="flex items-center gap-1.5 mt-1">
        <input
          v-model.number="editVal"
          type="number"
          inputmode="numeric"
          class="w-16 px-2 py-0.5 text-[13px] border border-ios-blue rounded-md outline-none tabular-nums"
          @keydown.enter.prevent="commitEdit"
        />
        <span class="text-[12px] text-ios-gray">kcal / {{ dish.unit_label }}</span>
        <button class="text-[12px] text-ios-blue font-medium ios-press" @click="commitEdit">Xong</button>
      </div>
      <button
        v-else
        type="button"
        class="flex items-center gap-1 mt-0.5 ios-press"
        @click="startEdit"
      >
        <span class="text-[12px] text-ios-gray">{{ dish.serving }} · {{ dishCalories(dish, dish.quantity) }} kcal</span>
        <svg viewBox="0 0 24 24" class="w-3 h-3 text-ios-gray3" fill="currentColor">
          <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a.996.996 0 0 0 0-1.41l-2.34-2.34a.996.996 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>
        </svg>
      </button>
    </div>

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
</template>
