<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useUiSettings, type FontScale } from '@/composables/useUiSettings'
import { useTheme, type ThemeName } from '@/composables/useTheme'

const router = useRouter()
const { fontScale, floatingChar } = useUiSettings()
const { theme } = useTheme()

const fontOptions: { value: FontScale; label: string }[] = [
  { value: 'small',  label: 'Nhỏ' },
  { value: 'medium', label: 'Trung bình' },
  { value: 'large',  label: 'Lớn' },
]

const themeOptions: { value: ThemeName; label: string; desc: string; from: string; to: string }[] = [
  { value: 'green',  label: 'CaloEye Xanh', desc: 'Xanh lá gốc (mặc định)', from: '#34C759', to: '#0F6E56' },
  { value: 'matcha', label: 'Soft Matcha',  desc: 'Xanh trà dịu (mới)',     from: '#8bab77', to: '#5e7a54' },
]
</script>

<template>
  <div class="pb-10">
    <!-- Header -->
    <div class="flex items-center gap-3 px-4 pt-2 pb-4">
      <button class="ios-press p-1 -ml-1" @click="router.back()">
        <svg viewBox="0 0 24 24" class="w-6 h-6" style="fill:var(--color-calor-green)">
          <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
        </svg>
      </button>
      <h1 class="text-[17px] font-semibold text-black">Hiển thị</h1>
    </div>

    <!-- Chủ đề màu -->
    <div class="px-4 mb-5">
      <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-2 px-1">Chủ đề màu</p>
      <div class="grid grid-cols-2 gap-3">
        <button
          v-for="opt in themeOptions"
          :key="opt.value"
          class="bg-white rounded-[16px] p-3 shadow-sm ios-press text-left transition-all"
          :class="theme === opt.value ? 'ring-2 ring-calor-green' : 'ring-1 ring-transparent'"
          @click="theme = opt.value"
        >
          <div class="h-14 rounded-[12px] mb-2.5 relative overflow-hidden" :style="`background: linear-gradient(135deg, ${opt.from}, ${opt.to})`">
            <div class="absolute right-2 bottom-1.5 text-[26px] opacity-25 leading-none">🥑</div>
            <div
              v-if="theme === opt.value"
              class="absolute top-1.5 right-1.5 w-5 h-5 rounded-full bg-white flex items-center justify-center shadow"
            >
              <svg viewBox="0 0 24 24" class="w-3.5 h-3.5" style="fill:var(--color-calor-green)"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
            </div>
          </div>
          <p class="text-[14px] font-semibold text-calor-deep">{{ opt.label }}</p>
          <p class="text-[11px] text-ios-gray">{{ opt.desc }}</p>
        </button>
      </div>
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
