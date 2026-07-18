<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useUiSettings, type FontScale } from '@/composables/useUiSettings'

const router = useRouter()
const { fontScale, floatingChar } = useUiSettings()

const fontOptions: { value: FontScale; label: string }[] = [
  { value: 'small',  label: 'Nhỏ' },
  { value: 'medium', label: 'Trung bình' },
  { value: 'large',  label: 'Lớn' },
]
</script>

<template>
  <div class="pb-10">
    <!-- Header -->
    <div class="flex items-center gap-3 px-4 pt-2 pb-4">
      <button class="ios-press p-1 -ml-1" @click="router.back()">
        <svg viewBox="0 0 24 24" class="w-6 h-6" fill="#18A874">
          <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
        </svg>
      </button>
      <h1 class="text-[17px] font-semibold text-black">Hiển thị</h1>
    </div>

    <!-- Cỡ chữ -->
    <div class="px-4 mb-5">
      <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-2 px-1">Cỡ chữ</p>
      <div class="bg-white rounded-[16px] px-4 py-4 shadow-sm">
        <div class="bg-ios-gray5 rounded-[10px] p-1 flex">
          <button
            v-for="opt in fontOptions"
            :key="opt.value"
            class="flex-1 py-2 rounded-[8px] text-[14px] font-semibold transition-all"
            :class="fontScale === opt.value ? 'bg-white text-black shadow-sm' : 'text-ios-gray'"
            @click="fontScale = opt.value"
          >{{ opt.label }}</button>
        </div>
        <p class="text-[12px] text-ios-gray mt-3 px-1">Áp dụng cho toàn bộ ứng dụng. Chọn cỡ phù hợp để dễ đọc hơn.</p>
      </div>
    </div>

    <!-- Nhân vật AVO -->
    <div class="px-4 mb-5">
      <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-2 px-1">Trợ lý AVO</p>
      <div class="bg-white rounded-[16px] px-4 py-3.5 shadow-sm flex items-center gap-3">
        <div class="w-9 h-9 rounded-[8px] bg-calor-green/10 flex items-center justify-center flex-shrink-0">
          <span class="text-lg">🥑</span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-[15px] text-black">Icon AVO bay</p>
          <p class="text-[12px] text-ios-gray mt-0.5">Nút trợ lý nổi ở các màn hình</p>
        </div>
        <button
          role="switch"
          :aria-checked="floatingChar"
          class="w-[51px] h-[31px] rounded-full transition-colors relative flex-shrink-0 ios-press"
          :class="floatingChar ? 'bg-ios-green' : 'bg-ios-gray4'"
          @click="floatingChar = !floatingChar"
        >
          <span
            class="absolute top-[2px] left-[2px] w-[27px] h-[27px] rounded-full bg-white shadow transition-transform"
            :class="floatingChar ? 'translate-x-[20px]' : ''"
          />
        </button>
      </div>
    </div>
  </div>
</template>
