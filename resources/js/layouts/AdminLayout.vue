<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { apiFetch } from '@/utils/api'
import { useToast } from '@/composables/useToast'
import AppToast from '@/components/ui/AppToast.vue'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu, DropdownMenuContent, DropdownMenuItem,
  DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  LayoutDashboard, Users, Bell, UtensilsCrossed, Database,
  Settings, ScrollText, LogOut, Menu, ChevronDown,
} from 'lucide-vue-next'

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
  { to: '/admin',               label: 'Tổng quan',    icon: LayoutDashboard },
  { to: '/admin/users',         label: 'Người dùng',   icon: Users },
  { to: '/admin/notifications', label: 'Thông báo',    icon: Bell },
  { to: '/admin/dishes',        label: 'Thư viện món', icon: UtensilsCrossed },
  { to: '/admin/dataset',       label: 'Dataset AI',   icon: Database },
  { to: '/admin/settings',      label: 'Cấu hình',     icon: Settings },
  { to: '/admin/audit-logs',    label: 'Nhật ký',      icon: ScrollText },
]

function isActive(to: string): boolean {
  if (to === '/admin') return route.path === '/admin'
  return route.path.startsWith(to)
}

onMounted(() => document.body.classList.add('admin-mode'))
onUnmounted(() => document.body.classList.remove('admin-mode'))
</script>

<template>
  <div class="min-h-dvh flex bg-muted/50 text-foreground">
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
          <component :is="item.icon" class="w-4.5 h-4.5 flex-none" />
          {{ item.label }}
        </RouterLink>
      </nav>
      <div class="p-3 border-t border-white/10">
        <button
          class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-white/70 hover:bg-white/10 hover:text-white transition-colors"
          @click="logout"
        >
          <LogOut class="w-4.5 h-4.5" />
          Đăng xuất
        </button>
      </div>
    </aside>

    <!-- Backdrop (mobile) -->
    <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-black/40 lg:hidden" @click="sidebarOpen = false" />

    <!-- Main -->
    <div class="flex-1 flex flex-col min-w-0">
      <header class="h-16 flex items-center justify-between px-4 lg:px-6 bg-background border-b sticky top-0 z-20">
        <Button variant="ghost" size="icon" class="lg:hidden -ml-2" @click="sidebarOpen = true">
          <Menu class="w-5 h-5" />
        </Button>
        <div class="flex-1" />
        <DropdownMenu>
          <DropdownMenuTrigger as-child>
            <button class="flex items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-accent transition-colors">
              <img v-if="user?.avatar_url" :src="user.avatar_url" class="w-8 h-8 rounded-full object-cover" alt="" />
              <div v-else class="w-8 h-8 rounded-full bg-primary text-primary-foreground flex items-center justify-center text-sm font-semibold">
                {{ (user?.name || 'A').charAt(0).toUpperCase() }}
              </div>
              <div class="text-right leading-tight hidden sm:block">
                <div class="text-sm font-semibold">{{ user?.name }}</div>
                <div class="text-xs text-muted-foreground">{{ user?.email }}</div>
              </div>
              <ChevronDown class="w-4 h-4 text-muted-foreground hidden sm:block" />
            </button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" class="w-48">
            <DropdownMenuLabel>Tài khoản</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem @click="router.push('/home')">Về app người dùng</DropdownMenuItem>
            <DropdownMenuItem variant="destructive" @click="logout">
              <LogOut class="w-4 h-4" /> Đăng xuất
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </header>

      <main class="flex-1 p-4 lg:p-6 overflow-y-auto">
        <slot />
      </main>
    </div>

    <AppToast />
  </div>
</template>
