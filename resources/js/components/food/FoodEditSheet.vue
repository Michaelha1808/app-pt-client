<script setup lang="ts">
export interface FoodEditValues {
  food_name: string
  serving:   string
  calories:  number
  protein:   number
  carbs:     number
  fat:       number
  sodium:    number
  grams?:    number | null   // khối lượng thật (g) — user tự sửa khi AI ước lượng sai từ ảnh (chỉ Result.vue dùng, MealPicker bỏ qua)
}

const props = withDefaults(defineProps<{
  open: boolean
  initial: FoodEditValues
  /** Nhãn ô calo — khác nhau tuỳ ngữ cảnh (calo cả phần vs calo/1 đơn vị khi có stepper số lượng riêng) */
  caloriesLabel?: string
}>(), {
  caloriesLabel: 'Calo (kcal)',
})

const emit = defineEmits<{
  'update:open': [boolean]
  save: [FoodEditValues]
}>()

const form = reactive<FoodEditValues>({ ...props.initial })

// Nạp lại form mỗi lần mở (không giữ nháp dở dang từ lần mở trước)
watch(() => props.open, (isOpen) => {
  if (isOpen) Object.assign(form, props.initial)
})

function close() {
  emit('update:open', false)
}

function save() {
  const clamp = (n: number, max: number) => Math.max(0, Math.min(max, Math.round(n || 0)))
  emit('save', {
    food_name: form.food_name.trim().slice(0, 200) || props.initial.food_name,
    serving:   form.serving.trim().slice(0, 200),
    calories:  clamp(form.calories, 10000),
    protein:   clamp(form.protein, 1000),
    carbs:     clamp(form.carbs, 2000),
    fat:       clamp(form.fat, 1000),
    sodium:    clamp(form.sodium, 20000),
    grams:     form.grams ? clamp(form.grams, 5000) : null,
  })
  close()
}
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center" @click.self="close">
        <div class="absolute inset-0 bg-black/40"/>
        <div class="relative w-full max-w-md bg-white rounded-t-[24px] px-5 pt-3 max-h-[88vh] overflow-y-auto animate-slideUpSheet" style="padding-bottom: calc(env(safe-area-inset-bottom) + 20px)">
          <div class="w-10 h-1 bg-ios-gray4 rounded-full mx-auto mb-4"/>
          <h2 class="text-[18px] font-semibold text-black mb-4">Sửa thông tin món ăn</h2>

          <!-- Tên món -->
          <p class="text-[13px] font-medium text-ios-gray mb-1.5">Tên món</p>
          <input
            v-model="form.food_name"
            type="text"
            maxlength="200"
            placeholder="Tên món ăn"
            class="w-full py-3 px-3.5 rounded-[12px] bg-ios-gray6 text-[16px] font-medium text-black outline-none focus:ring-1 focus:ring-ios-blue mb-4"
          />

          <!-- Khẩu phần / số lượng / khối lượng -->
          <p class="text-[13px] font-medium text-ios-gray mb-1.5">Khẩu phần (số lượng, khối lượng...)</p>
          <input
            v-model="form.serving"
            type="text"
            maxlength="200"
            placeholder="VD: 1 tô lớn (~500ml), 2 phần (300g)..."
            class="w-full py-3 px-3.5 rounded-[12px] bg-ios-gray6 text-[15px] text-black outline-none focus:ring-1 focus:ring-ios-blue mb-4"
          />

          <!-- Khối lượng thật (gram) — sửa khi AI ước lượng khối lượng từ ảnh không chính xác -->
          <p class="text-[13px] font-medium text-ios-gray mb-1.5">Khối lượng thực tế (gram)</p>
          <input
            v-model.number="form.grams"
            type="number"
            inputmode="numeric"
            min="1" max="5000"
            placeholder="VD: 350 (bỏ trống nếu giữ ước tính của AI)"
            class="w-full py-3 px-3.5 rounded-[12px] bg-ios-gray6 text-[15px] text-black outline-none focus:ring-1 focus:ring-ios-blue mb-4 tabular-nums"
          />

          <!-- Calo -->
          <p class="text-[13px] font-medium text-ios-gray mb-1.5">{{ caloriesLabel }}</p>
          <input
            v-model.number="form.calories"
            type="number"
            inputmode="numeric"
            min="0" max="10000"
            class="w-full py-3 px-3.5 rounded-[12px] bg-ios-gray6 text-[22px] font-bold text-ios-blue outline-none focus:ring-1 focus:ring-ios-blue mb-4 tabular-nums"
          />

          <!-- Macros -->
          <p class="text-[13px] font-medium text-ios-gray mb-2">Thành phần dinh dưỡng</p>
          <div class="grid grid-cols-2 gap-3 mb-2">
            <div>
              <label class="flex items-center gap-1.5 text-[12px] text-ios-gray mb-1.5">
                <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:var(--color-calor-green)"/>
                Đạm (g)
              </label>
              <input v-model.number="form.protein" type="number" inputmode="numeric" min="0" max="1000"
                class="w-full py-2.5 px-3 rounded-[10px] bg-ios-gray6 text-[15px] font-medium text-black outline-none focus:ring-1 focus:ring-ios-blue tabular-nums"/>
            </div>
            <div>
              <label class="flex items-center gap-1.5 text-[12px] text-ios-gray mb-1.5">
                <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:#FF9500"/>
                Tinh bột (g)
              </label>
              <input v-model.number="form.carbs" type="number" inputmode="numeric" min="0" max="2000"
                class="w-full py-2.5 px-3 rounded-[10px] bg-ios-gray6 text-[15px] font-medium text-black outline-none focus:ring-1 focus:ring-ios-blue tabular-nums"/>
            </div>
            <div>
              <label class="flex items-center gap-1.5 text-[12px] text-ios-gray mb-1.5">
                <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:#FF2D55"/>
                Chất béo (g)
              </label>
              <input v-model.number="form.fat" type="number" inputmode="numeric" min="0" max="1000"
                class="w-full py-2.5 px-3 rounded-[10px] bg-ios-gray6 text-[15px] font-medium text-black outline-none focus:ring-1 focus:ring-ios-blue tabular-nums"/>
            </div>
            <div>
              <label class="flex items-center gap-1.5 text-[12px] text-ios-gray mb-1.5">
                <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:#8a9a7d"/>
                Natri (mg)
              </label>
              <input v-model.number="form.sodium" type="number" inputmode="numeric" min="0" max="20000"
                class="w-full py-2.5 px-3 rounded-[10px] bg-ios-gray6 text-[15px] font-medium text-black outline-none focus:ring-1 focus:ring-ios-blue tabular-nums"/>
            </div>
          </div>

          <div class="flex gap-3 mt-5">
            <button
              class="flex-1 h-[50px] rounded-[14px] bg-ios-gray6 text-black text-[15px] font-semibold ios-press"
              @click="close"
            >Hủy</button>
            <button
              class="flex-[2] h-[50px] rounded-[14px] bg-calor-green text-white text-[16px] font-semibold ios-press"
              @click="save"
            >Lưu thay đổi</button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease }
.fade-enter-from, .fade-leave-to       { opacity: 0 }
</style>
