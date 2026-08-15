<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
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
  Settings, ScrollText, LogOut, Menu, ChevronDown, ChevronRight,
  Smartphone, MessageCircle,
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

// Nav nhóm theo khu vực (kiểu Gentelella: section label + menu items)
const navGroups = [
  {
    label: 'Quản lý',
    items: [
      { to: '/admin',               label: 'Tổng quan',  icon: LayoutDashboard },
      { to: '/admin/users',         label: 'Người dùng', icon: Users },
      { to: '/admin/notifications', label: 'Thông báo',  icon: Bell },
    ],
  },
  {
    label: 'Nội dung & AI',
    items: [
      { to: '/admin/dishes',    label: 'Thư viện món',    icon: UtensilsCrossed },
      { to: '/admin/dataset',   label: 'Dataset AI',      icon: Database },
      { to: '/admin/chat-logs', label: 'Nhật ký Chat AI', icon: MessageCircle },
    ],
  },
  {
    label: 'Hệ thống',
    items: [
      { to: '/admin/settings',   label: 'Cấu hình', icon: Settings },
      { to: '/admin/audit-logs', label: 'Nhật ký',  icon: ScrollText },
    ],
  },
]

function isActive(to: string): boolean {
  if (to === '/admin') return route.path === '/admin'
  return route.path.startsWith(to)
}

/** Tên màn hiện tại cho breadcrumb topbar. */
const currentLabel = computed(() => {
  for (const g of navGroups) {
    const hit = g.items.find(i => isActive(i.to))
    if (hit) return hit.label
  }
  return 'Quản trị'
})

onMounted(() => document.body.classList.add('admin-mode'))
onUnmounted(() => document.body.classList.remove('admin-mode'))
</script>

<template>
  <!-- App frame: sidebar cố định, content cuộn bên trong cột phải (footer luôn nằm cuối nội dung) -->
  <div class="h-dvh flex bg-[#f3f4f6] text-foreground overflow-hidden">
    <!-- ══ Sidebar ══ -->
    <aside
      class="fixed lg:static inset-y-0 left-0 z-40 w-64 bg-[#0C4D3D] text-white flex flex-col transition-transform duration-200"
      :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
    >
      <!-- Brand -->
      <div class="h-16 flex items-center gap-2.5 px-5 border-b border-white/10 flex-none">
        <div class="w-8 h-8 rounded-lg bg-calor-green flex items-center justify-center font-bold text-white shadow-md shadow-black/20">C</div>
        <div class="leading-tight">
          <div class="text-[15px] font-bold tracking-tight">CaloEye</div>
          <div class="text-[10px] uppercase tracking-widest text-calor-mint/80">Admin Panel</div>
        </div>
      </div>

      <!-- Profile block (kiểu Gentelella) -->
      <div class="px-5 py-4 flex items-center gap-3 bg-black/15 border-b border-white/10 flex-none">
        <img v-if="user?.avatar_url" :src="user.avatar_url" class="w-11 h-11 rounded-full object-cover ring-2 ring-calor-mint/40" alt="" />
        <div v-else class="w-11 h-11 rounded-full bg-calor-green flex items-center justify-center text-base font-semibold ring-2 ring-calor-mint/40">
          {{ (user?.name || 'A').charAt(0).toUpperCase() }}
        </div>
        <div class="min-w-0 leading-tight">
          <div class="text-[11px] text-white/50">Xin chào,</div>
          <div class="text-sm font-semibold truncate">{{ user?.name || 'Admin' }}</div>
          <div class="flex items-center gap-1 mt-0.5">
            <span class="w-1.5 h-1.5 rounded-full bg-calor-mint inline-block" />
            <span class="text-[10px] text-calor-mint/90">Quản trị viên</span>
          </div>
        </div>
      </div>

      <!-- Nav -->
      <nav class="flex-1 py-3 overflow-y-auto">
        <div v-for="group in navGroups" :key="group.label" class="px-3 mb-2">
          <div class="px-3 pt-2 pb-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-white/35">
            {{ group.label }}
          </div>
          <RouterLink
            v-for="item in group.items" :key="item.to" :to="item.to"
            class="group flex items-center gap-3 pl-3 pr-2 py-2.5 rounded-lg text-sm font-medium transition-colors border-l-[3px]"
            :class="isActive(item.to)
              ? 'bg-white/12 text-white border-calor-mint shadow-[inset_0_1px_0_rgba(255,255,255,0.06)]'
              : 'text-white/65 border-transparent hover:bg-white/8 hover:text-white'"
            @click="sidebarOpen = false"
          >
            <component :is="item.icon" class="w-4.5 h-4.5 flex-none" :class="isActive(item.to) ? 'text-calor-mint' : ''" />
            <span class="flex-1">{{ item.label }}</span>
            <ChevronRight class="w-3.5 h-3.5 opacity-0 group-hover:opacity-40 transition-opacity" />
          </RouterLink>
        </div>
      </nav>

      <!-- Quick actions (hàng icon dưới cùng, kiểu Gentelella) -->
      <div class="flex-none border-t border-white/10 grid grid-cols-2 divide-x divide-white/10">
        <button
          class="flex items-center justify-center gap-2 py-3 text-[12px] text-white/60 hover:text-white hover:bg-white/8 transition-colors"
          title="Về app người dùng"
          @click="router.push('/home')"
        >
          <Smartphone class="w-4 h-4" /> Về app
        </button>
        <button
          class="flex items-center justify-center gap-2 py-3 text-[12px] text-white/60 hover:text-white hover:bg-white/8 transition-colors"
          title="Đăng xuất"
          @click="logout"
        >
          <LogOut class="w-4 h-4" /> Đăng xuất
        </button>
      </div>
    </aside>

    <!-- Backdrop (mobile) -->
    <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-black/40 lg:hidden" @click="sidebarOpen = false" />

    <!-- ══ Main ══ -->
    <div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
      <header class="h-16 flex items-center gap-3 px-4 lg:px-6 bg-background border-b sticky top-0 z-20">
        <Button variant="ghost" size="icon" class="lg:hidden -ml-2" @click="sidebarOpen = true">
          <Menu class="w-5 h-5" />
        </Button>

        <!-- Breadcrumb -->
        <div class="flex items-center gap-1.5 text-sm min-w-0">
          <span class="text-muted-foreground hidden sm:inline">Quản trị</span>
          <ChevronRight class="w-3.5 h-3.5 text-muted-foreground/50 hidden sm:inline" />
          <span class="font-semibold truncate">{{ currentLabel }}</span>
        </div>

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

      <main class="flex-1 p-4 lg:p-6">
        <slot />
      </main>

      <footer class="flex-none px-4 lg:px-6 py-3 text-[11px] text-muted-foreground/70 flex items-center justify-between border-t bg-background/60">
        <span>CaloEye Admin</span>
        <span>Laravel + Vue · {{ new Date().getFullYear() }}</span>
      </footer>
    </div>

    <AppToast />
  </div>
</template>
