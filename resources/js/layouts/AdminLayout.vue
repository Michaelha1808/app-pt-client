<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { apiFetch } from '@/utils/api'
import { useToast } from '@/composables/useToast'
import AppToast from '@/components/ui/AppToast.vue'

const route = useRoute()
const router = useRouter()
const store = useAuthStore()
const { user } = storeToRefs(store)
const { success } = useToast()

const sidebarOpen = ref(false)

async function logout() {
  try { await apiFetch('/auth/logout', { method: 'POST' }) } catch {}
  store.token = null
  store.user = null
  store.isGuest = false
  success('Đã đăng xuất')
  router.replace('/admin/login')
}

const nav = [
  { to: '/admin',            label: 'Tổng quan',    icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6' },
  { to: '/admin/users',      label: 'Người dùng',   icon: 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z' },
  { to: '/admin/notifications', label: 'Thông báo', icon: 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9' },
  { to: '/admin/settings',   label: 'Cấu hình',     icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z' },
  { to: '/admin/audit-logs', label: 'Nhật ký',      icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01' },
]

function isActive(to: string): boolean {
  if (to === '/admin') return route.path === '/admin'
  return route.path.startsWith(to)
}

onMounted(() => document.body.classList.add('admin-mode'))
onUnmounted(() => document.body.classList.remove('admin-mode'))
</script>

<template>
  <div class="min-h-dvh flex bg-gray-100 text-gray-800">
    <!-- Sidebar -->
    <aside
      class="fixed lg:static inset-y-0 left-0 z-40 w-60 bg-[#0C4D3D] text-white flex flex-col transition-transform duration-200"
      :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
    >
      <div class="h-16 flex items-center gap-2 px-5 border-b border-white/10">
        <span class="text-lg font-bold">CaloEye</span>
        <span class="text-xs px-1.5 py-0.5 rounded bg-calor-green/30 text-calor-mint">Admin</span>
      </div>
      <nav class="flex-1 py-4 space-y-1 px-3">
        <RouterLink
          v-for="item in nav" :key="item.to" :to="item.to"
          class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors"
          :class="isActive(item.to) ? 'bg-white/15 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white'"
          @click="sidebarOpen = false"
        >
          <svg class="w-5 h-5 flex-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
          </svg>
          {{ item.label }}
        </RouterLink>
      </nav>
      <div class="p-3 border-t border-white/10">
        <button
          class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-white/70 hover:bg-white/10 hover:text-white"
          @click="logout"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
          </svg>
          Đăng xuất
        </button>
      </div>
    </aside>

    <!-- Backdrop (mobile) -->
    <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-black/40 lg:hidden" @click="sidebarOpen = false" />

    <!-- Main -->
    <div class="flex-1 flex flex-col min-w-0">
      <header class="h-16 flex items-center justify-between px-4 lg:px-6 bg-white border-b border-gray-200 sticky top-0 z-20">
        <button class="lg:hidden p-2 -ml-2 text-gray-600" @click="sidebarOpen = true">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        </button>
        <div class="flex-1" />
        <div class="flex items-center gap-3">
          <img v-if="user?.avatar_url" :src="user.avatar_url" class="w-8 h-8 rounded-full object-cover" alt="" />
          <div v-else class="w-8 h-8 rounded-full bg-calor-green text-white flex items-center justify-center text-sm font-semibold">
            {{ (user?.name || 'A').charAt(0).toUpperCase() }}
          </div>
          <div class="text-right leading-tight hidden sm:block">
            <div class="text-sm font-semibold text-gray-800">{{ user?.name }}</div>
            <div class="text-xs text-gray-400">{{ user?.email }}</div>
          </div>
        </div>
      </header>

      <main class="flex-1 p-4 lg:p-6 overflow-y-auto">
        <slot />
      </main>
    </div>

    <AppToast />
  </div>
</template>
