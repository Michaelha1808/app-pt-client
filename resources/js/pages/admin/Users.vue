<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAdmin } from '@/composables/useAdmin'
import { useAuth } from '@/composables/useAuth'
import { useToast } from '@/composables/useToast'
import type { AdminUserRow, UsersQuery } from '@/types/admin'

const router = useRouter()
const { fetchUsers, suspendUser, restoreUser, resetUserPassword, deleteUser } = useAdmin()
const { extractError } = useAuth()
const toast = useToast()

const rows = ref<AdminUserRow[]>([])
const loading = ref(true)
const meta = ref({ current_page: 1, per_page: 20, total: 0, last_page: 1 })

const filters = ref<UsersQuery>({
  search: '', role: '', status: '', provider: '',
  sort: 'created_at', order: 'desc', page: 1, per_page: 20,
})

let searchTimer: ReturnType<typeof setTimeout> | undefined
const menuOpen = ref<number | null>(null)

async function load() {
  loading.value = true
  try {
    const res = await fetchUsers(filters.value)
    rows.value = res.data
    meta.value = res.meta
  } catch (e) {
    toast.error(extractError(e) || 'Không tải được danh sách')
  } finally {
    loading.value = false
  }
}

watch(() => filters.value.search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { filters.value.page = 1; load() }, 300)
})

function applyFilter() { filters.value.page = 1; load() }

function setSort(col: string) {
  if (filters.value.sort === col) {
    filters.value.order = filters.value.order === 'asc' ? 'desc' : 'asc'
  } else {
    filters.value.sort = col; filters.value.order = 'desc'
  }
  load()
}

function goPage(p: number) {
  if (p < 1 || p > meta.value.last_page) return
  filters.value.page = p; load()
}

async function onSuspend(u: AdminUserRow) {
  menuOpen.value = null
  const reason = window.prompt(`Khoá tài khoản "${u.name}"? Nhập lý do (tuỳ chọn):`)
  if (reason === null) return
  try {
    await suspendUser(u.id, reason || undefined)
    toast.success('Đã khoá tài khoản'); load()
  } catch (e) { toast.error(extractError(e)) }
}

async function onRestore(u: AdminUserRow) {
  menuOpen.value = null
  try {
    await restoreUser(u.id)
    toast.success('Đã mở khoá'); load()
  } catch (e) { toast.error(extractError(e)) }
}

async function onReset(u: AdminUserRow) {
  menuOpen.value = null
  if (!confirm(`Gửi email đặt lại mật khẩu cho ${u.email}?`)) return
  try {
    const res = await resetUserPassword(u.id)
    toast.success(res.message)
  } catch (e) { toast.error(extractError(e)) }
}

async function onDelete(u: AdminUserRow) {
  menuOpen.value = null
  if (!confirm(`Xoá tài khoản "${u.name}"? Hành động này có thể khôi phục nhưng người dùng sẽ mất truy cập.`)) return
  try {
    await deleteUser(u.id)
    toast.success('Đã xoá tài khoản'); load()
  } catch (e) { toast.error(extractError(e)) }
}

function fmtDate(s: string | null): string {
  if (!s) return '—'
  return new Date(s).toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

onMounted(load)
</script>

<template>
  <div @click="menuOpen = null">
    <h1 class="text-xl font-bold text-gray-900 mb-4">Người dùng</h1>

    <!-- Filters -->
    <div class="bg-white rounded-xl border border-gray-200 p-3 mb-4 flex flex-wrap gap-2 items-center">
      <input
        v-model="filters.search" placeholder="Tìm tên hoặc email…"
        class="flex-1 min-w-[200px] px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-calor-green/40"
      />
      <select v-model="filters.role" @change="applyFilter" class="px-2 py-2 text-sm border border-gray-200 rounded-lg">
        <option value="">Tất cả vai trò</option>
        <option value="user">User</option>
        <option value="admin">Admin</option>
      </select>
      <select v-model="filters.status" @change="applyFilter" class="px-2 py-2 text-sm border border-gray-200 rounded-lg">
        <option value="">Mọi trạng thái</option>
        <option value="active">Active</option>
        <option value="suspended">Bị khoá</option>
      </select>
      <select v-model="filters.provider" @change="applyFilter" class="px-2 py-2 text-sm border border-gray-200 rounded-lg">
        <option value="">Mọi nguồn</option>
        <option value="email">Email</option>
        <option value="google">Google</option>
        <option value="facebook">Facebook</option>
      </select>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
              <th class="text-left font-medium px-4 py-3 cursor-pointer" @click="setSort('name')">
                Người dùng <span v-if="filters.sort === 'name'">{{ filters.order === 'asc' ? '▲' : '▼' }}</span>
              </th>
              <th class="text-left font-medium px-4 py-3">Vai trò</th>
              <th class="text-left font-medium px-4 py-3">Trạng thái</th>
              <th class="text-right font-medium px-4 py-3">Streak</th>
              <th class="text-right font-medium px-4 py-3">Meal logs</th>
              <th class="text-left font-medium px-4 py-3 cursor-pointer" @click="setSort('last_seen_at')">
                Hoạt động <span v-if="filters.sort === 'last_seen_at'">{{ filters.order === 'asc' ? '▲' : '▼' }}</span>
              </th>
              <th class="text-left font-medium px-4 py-3 cursor-pointer" @click="setSort('created_at')">
                Tạo lúc <span v-if="filters.sort === 'created_at'">{{ filters.order === 'asc' ? '▲' : '▼' }}</span>
              </th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading">
              <td colspan="8" class="px-4 py-10 text-center text-gray-400">Đang tải…</td>
            </tr>
            <tr v-else-if="!rows.length">
              <td colspan="8" class="px-4 py-10 text-center text-gray-400">Không tìm thấy người dùng</td>
            </tr>
            <tr
              v-for="u in rows" :key="u.id"
              class="hover:bg-gray-50 cursor-pointer"
              @click="router.push(`/admin/users/${u.id}`)"
            >
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <img v-if="u.avatar_url" :src="u.avatar_url" class="w-8 h-8 rounded-full object-cover" alt="" />
                  <div v-else class="w-8 h-8 rounded-full bg-calor-light text-calor-deep flex items-center justify-center text-xs font-semibold">
                    {{ u.name.charAt(0).toUpperCase() }}
                  </div>
                  <div class="min-w-0">
                    <div class="font-medium text-gray-800 truncate">{{ u.name }}</div>
                    <div class="text-xs text-gray-400 truncate">{{ u.email }}</div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3">
                <span class="text-xs px-2 py-0.5 rounded-full"
                      :class="u.role === 'admin' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600'">
                  {{ u.role }}
                </span>
              </td>
              <td class="px-4 py-3">
                <span class="text-xs px-2 py-0.5 rounded-full"
                      :class="u.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">
                  {{ u.status === 'active' ? 'Active' : 'Khoá' }}
                </span>
              </td>
              <td class="px-4 py-3 text-right text-gray-600">{{ u.calorie_streak }}</td>
              <td class="px-4 py-3 text-right text-gray-600">{{ u.meal_logs_count }}</td>
              <td class="px-4 py-3 text-gray-500">{{ fmtDate(u.last_seen_at) }}</td>
              <td class="px-4 py-3 text-gray-500">{{ fmtDate(u.created_at) }}</td>
              <td class="px-4 py-3 text-right relative" @click.stop>
                <button class="p-1.5 rounded hover:bg-gray-100 text-gray-500"
                        @click="menuOpen = menuOpen === u.id ? null : u.id">
                  <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zm0 6a2 2 0 110-4 2 2 0 010 4zm0 6a2 2 0 110-4 2 2 0 010 4z" /></svg>
                </button>
                <div v-if="menuOpen === u.id"
                     class="absolute right-2 top-10 z-10 w-44 bg-white rounded-lg shadow-lg border border-gray-200 py-1 text-left">
                  <button class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50" @click="router.push(`/admin/users/${u.id}`)">Xem chi tiết</button>
                  <button v-if="u.status === 'active'" class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 text-orange-600" @click="onSuspend(u)">Khoá tài khoản</button>
                  <button v-else class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 text-green-600" @click="onRestore(u)">Mở khoá</button>
                  <button class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50" @click="onReset(u)">Reset mật khẩu</button>
                  <button class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 text-red-600" @click="onDelete(u)">Xoá</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div class="flex items-center justify-between px-4 py-3 border-t border-gray-100 text-sm text-gray-500">
        <span>Tổng {{ meta.total }} người dùng</span>
        <div class="flex items-center gap-1">
          <button class="px-2 py-1 rounded hover:bg-gray-100 disabled:opacity-40"
                  :disabled="meta.current_page <= 1" @click="goPage(meta.current_page - 1)">‹</button>
          <span class="px-2">Trang {{ meta.current_page }} / {{ meta.last_page }}</span>
          <button class="px-2 py-1 rounded hover:bg-gray-100 disabled:opacity-40"
                  :disabled="meta.current_page >= meta.last_page" @click="goPage(meta.current_page + 1)">›</button>
        </div>
      </div>
    </div>
  </div>
</template>
