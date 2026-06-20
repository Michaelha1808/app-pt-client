<script setup lang="ts">
import { useRoute } from 'vue-router'

const route = useRoute()

const tabs = [
  {
    name: 'Trang chủ',
    path: '/home',
    icon: `<path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/>`,
    viewBox: '0 0 24 24',
  },
  {
    name: 'Lịch sử',
    path: '/history',
    icon: `<path d="M13 3a9 9 0 0 0-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42A8.954 8.954 0 0 0 13 21a9 9 0 0 0 0-18zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/>`,
    viewBox: '0 0 24 24',
  },
  {
    name: 'Nhận diện',
    path: '/scan',
    icon: null, // special center button
    viewBox: '',
  },
  {
    name: 'Tư vấn',
    path: '/chat',
    icon: `<path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/>`,
    viewBox: '0 0 24 24',
  },
  {
    name: 'Hồ sơ',
    path: '/profile',
    icon: `<path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>`,
    viewBox: '0 0 24 24',
  },
]

function isActive(path: string) {
  return route.path === path || route.path.startsWith(path + '/')
}
</script>

<template>
  <nav v-if="route.path !== '/scan'" class="flex-none relative z-10 select-none">
    <!-- Blur background as separate layer so -mt-6 center button isn't clipped by backdrop-filter stacking context -->
    <div class="absolute inset-0 ios-blur-white border-t-hairline border-black/12 pointer-events-none"/>
    <div class="relative flex items-end justify-around px-2 pt-2 pb-6">
      <template v-for="tab in tabs" :key="tab.path">
        <!-- Center scan button -->
        <NuxtLink
          v-if="tab.path === '/scan'"
          to="/scan"
          class="flex flex-col items-center -mt-6 ios-press"
        >
          <div
            class="w-[58px] h-[58px] rounded-full flex items-center justify-center shadow-lg shadow-ios-blue/35"
            :class="isActive('/scan')
              ? 'bg-ios-blue'
              : 'bg-gradient-to-br from-ios-blue to-[#5AC8FA]'"
          >
            <svg viewBox="0 0 24 24" fill="white" class="w-7 h-7">
              <path d="M4 4h3V2H2v5h2V4zm13-2v2h3v3h2V2h-5zm3 16h-3v2h5v-5h-2v3zM4 17H2v5h5v-2H4v-3zM15 9H9v6h6V9zm-2 4h-2v-2h2v2zm-7 0V9l1-1h6l1 1v4l-1 1H7l-1-1z"/>
            </svg>
          </div>
          <span
            class="text-[10px] mt-1 font-medium"
            :class="isActive('/scan') ? 'text-ios-blue' : 'text-ios-gray'"
          >Nhận diện</span>
        </NuxtLink>

        <!-- Regular tabs -->
        <NuxtLink
          v-else
          :to="tab.path"
          class="flex flex-col items-center gap-[3px] px-3 py-1 ios-press"
        >
          <svg
            viewBox="0 0 24 24"
            class="w-[26px] h-[26px] transition-colors duration-150"
            :fill="isActive(tab.path) ? '#007AFF' : '#8E8E93'"
            v-html="tab.icon"
          />
          <span
            class="text-[10px] font-medium transition-colors duration-150"
            :class="isActive(tab.path) ? 'text-ios-blue' : 'text-ios-gray'"
          >{{ tab.name }}</span>
        </NuxtLink>
      </template>
    </div>
  </nav>
</template>

