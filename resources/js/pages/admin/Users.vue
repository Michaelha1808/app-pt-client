<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAdmin } from '@/composables/useAdmin'
import { useAuth } from '@/composables/useAuth'
import { useToast } from '@/composables/useToast'
import type { AdminUserRow, UsersQuery } from '@/types/admin'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import {
  DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table'
import { ChevronLeft, ChevronRight, MoreHorizontal, ArrowUp, ArrowDown } from 'lucide-vue-next'

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

// shadcn Select không cho value rỗng → dùng sentinel 'all' map về ''
function filterModel(key: 'role' | 'status' | 'provider') {
  return computed({
    get: () => (filters.value[key] as string) || 'all',
    set: (v: string) => { filters.value[key] = v === 'all' ? '' : v; applyFilter() },
  })
}
const roleFilter = filterModel('role')
const statusFilter = filterModel('status')
const providerFilter = filterModel('provider')

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
  const reason = window.prompt(`Khoá tài khoản "${u.name}"? Nhập lý do (tuỳ chọn):`)
  if (reason === null) return
  try {
    await suspendUser(u.id, reason || undefined)
    toast.success('Đã khoá tài khoản'); load()
  } catch (e) { toast.error(extractError(e)) }
}

async function onRestore(u: AdminUserRow) {
  try {
    await restoreUser(u.id)
    toast.success('Đã mở khoá'); load()
  } catch (e) { toast.error(extractError(e)) }
}

async function onReset(u: AdminUserRow) {
  if (!confirm(`Gửi email đặt lại mật khẩu cho ${u.email}?`)) return
  try {
    const res = await resetUserPassword(u.id)
    toast.success(res.message)
  } catch (e) { toast.error(extractError(e)) }
}

async function onDelete(u: AdminUserRow) {
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
  <div>
    <div class="mb-5">
      <h1 class="text-xl font-bold">Người dùng</h1>
      <p class="text-sm text-muted-foreground mt-0.5">Tìm kiếm, phân quyền và quản lý trạng thái tài khoản.</p>
    </div>

    <!-- Filters -->
    <Card class="p-3 mb-4 flex-row flex-wrap gap-2 items-center">
      <Input
        v-model="filters.search" placeholder="Tìm tên hoặc email…"
        class="flex-1 min-w-[200px]"
      />
      <Select v-model="roleFilter">
        <SelectTrigger class="w-40"><SelectValue /></SelectTrigger>
        <SelectContent>
          <SelectItem value="all">Tất cả vai trò</SelectItem>
          <SelectItem value="user">User</SelectItem>
          <SelectItem value="admin">Admin</SelectItem>
        </SelectContent>
      </Select>
      <Select v-model="statusFilter">
        <SelectTrigger class="w-40"><SelectValue /></SelectTrigger>
        <SelectContent>
          <SelectItem value="all">Mọi trạng thái</SelectItem>
          <SelectItem value="active">Active</SelectItem>
          <SelectItem value="suspended">Bị khoá</SelectItem>
        </SelectContent>
      </Select>
      <Select v-model="providerFilter">
        <SelectTrigger class="w-40"><SelectValue /></SelectTrigger>
        <SelectContent>
          <SelectItem value="all">Mọi nguồn</SelectItem>
          <SelectItem value="email">Email</SelectItem>
          <SelectItem value="google">Google</SelectItem>
          <SelectItem value="facebook">Facebook</SelectItem>
        </SelectContent>
      </Select>
    </Card>

    <!-- Table -->
    <Card class="py-0 gap-0 overflow-hidden">
      <div class="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead class="cursor-pointer select-none" @click="setSort('name')">
                <span class="inline-flex items-center gap-1">
                  Người dùng
                  <component :is="filters.order === 'asc' ? ArrowUp : ArrowDown" v-if="filters.sort === 'name'" class="w-3.5 h-3.5" />
                </span>
              </TableHead>
              <TableHead>Vai trò</TableHead>
              <TableHead>Trạng thái</TableHead>
              <TableHead class="text-right">Streak</TableHead>
              <TableHead class="text-right">Meal logs</TableHead>
              <TableHead class="cursor-pointer select-none" @click="setSort('last_seen_at')">
                <span class="inline-flex items-center gap-1">
                  Hoạt động
                  <component :is="filters.order === 'asc' ? ArrowUp : ArrowDown" v-if="filters.sort === 'last_seen_at'" class="w-3.5 h-3.5" />
                </span>
              </TableHead>
              <TableHead class="cursor-pointer select-none" @click="setSort('created_at')">
                <span class="inline-flex items-center gap-1">
                  Tạo lúc
                  <component :is="filters.order === 'asc' ? ArrowUp : ArrowDown" v-if="filters.sort === 'created_at'" class="w-3.5 h-3.5" />
                </span>
              </TableHead>
              <TableHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            <template v-if="loading">
              <TableRow v-for="i in 5" :key="i">
                <TableCell v-for="j in 8" :key="j"><Skeleton class="h-4 w-full" /></TableCell>
              </TableRow>
            </template>
            <TableRow v-else-if="!rows.length">
              <TableCell colspan="8" class="py-10 text-center text-muted-foreground">Không tìm thấy người dùng</TableCell>
            </TableRow>
            <TableRow
              v-for="u in rows" v-else :key="u.id"
              class="cursor-pointer"
              @click="router.push(`/admin/users/${u.id}`)"
            >
              <TableCell>
                <div class="flex items-center gap-3">
                  <img v-if="u.avatar_url" :src="u.avatar_url" class="w-8 h-8 rounded-full object-cover" alt="" />
                  <div v-else class="w-8 h-8 rounded-full bg-calor-light text-calor-deep flex items-center justify-center text-xs font-semibold">
                    {{ u.name.charAt(0).toUpperCase() }}
                  </div>
                  <div class="min-w-0">
                    <div class="font-medium truncate">{{ u.name }}</div>
                    <div class="text-xs text-muted-foreground truncate">{{ u.email }}</div>
                  </div>
                </div>
              </TableCell>
              <TableCell>
                <Badge :variant="u.role === 'admin' ? 'default' : 'secondary'" class="capitalize">{{ u.role }}</Badge>
              </TableCell>
              <TableCell>
                <Badge
                  :class="u.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'"
                  variant="secondary"
                >{{ u.status === 'active' ? 'Active' : 'Khoá' }}</Badge>
              </TableCell>
              <TableCell class="text-right text-muted-foreground tabular-nums">{{ u.calorie_streak }}</TableCell>
              <TableCell class="text-right text-muted-foreground tabular-nums">{{ u.meal_logs_count }}</TableCell>
              <TableCell class="text-muted-foreground">{{ fmtDate(u.last_seen_at) }}</TableCell>
              <TableCell class="text-muted-foreground">{{ fmtDate(u.created_at) }}</TableCell>
              <TableCell class="text-right" @click.stop>
                <DropdownMenu>
                  <DropdownMenuTrigger as-child>
                    <Button variant="ghost" size="icon" class="h-8 w-8">
                      <MoreHorizontal class="w-4 h-4" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end" class="w-44">
                    <DropdownMenuItem @click="router.push(`/admin/users/${u.id}`)">Xem chi tiết</DropdownMenuItem>
                    <DropdownMenuItem v-if="u.status === 'active'" @click="onSuspend(u)">Khoá tài khoản</DropdownMenuItem>
                    <DropdownMenuItem v-else @click="onRestore(u)">Mở khoá</DropdownMenuItem>
                    <DropdownMenuItem @click="onReset(u)">Reset mật khẩu</DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem variant="destructive" @click="onDelete(u)">Xoá</DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>

      <!-- Pagination -->
      <div class="flex items-center justify-between px-4 py-3 border-t text-sm text-muted-foreground">
        <span>Tổng {{ meta.total }} người dùng</span>
        <div class="flex items-center gap-1">
          <Button variant="ghost" size="icon" class="h-8 w-8" :disabled="meta.current_page <= 1" @click="goPage(meta.current_page - 1)">
            <ChevronLeft class="w-4 h-4" />
          </Button>
          <span class="px-2">Trang {{ meta.current_page }} / {{ meta.last_page }}</span>
          <Button variant="ghost" size="icon" class="h-8 w-8" :disabled="meta.current_page >= meta.last_page" @click="goPage(meta.current_page + 1)">
            <ChevronRight class="w-4 h-4" />
          </Button>
        </div>
      </div>
    </Card>
  </div>
</template>
