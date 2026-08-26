<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useAdmin } from '@/composables/useAdmin'
import { useAuth } from '@/composables/useAuth'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import type { AdminUserRow, UsersQuery } from '@/types/admin'
import EmptyState from '@/components/admin/EmptyState.vue'
import IconAction from '@/components/admin/IconAction.vue'
import {
  AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
  AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import {
  Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table'
import {
  ChevronLeft, ChevronRight, ArrowUp, ArrowDown, Search,
  Eye, Lock, LockOpen, KeyRound, Trash2, SearchX, Loader2,
} from 'lucide-vue-next'

const router = useRouter()
const { fetchUsers, suspendUser, restoreUser, resetUserPassword, deleteUser } = useAdmin()
const { extractError } = useAuth()
const { user: me } = storeToRefs(useAuthStore())
const toast = useToast()

// Tài khoản admin đang đăng nhập — ẩn nút tự khoá/tự xoá chính mình (backend cũng chặn).
function isSelf(u: AdminUserRow): boolean {
  return !!me.value && String(me.value.id) === String(u.id)
}

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

// ── Hành động (confirm bằng dialog thay window.confirm/prompt) ──
const suspendTarget = ref<AdminUserRow | null>(null)
const suspendReason = ref('')
const suspendBusy = ref(false)
const resetTarget = ref<AdminUserRow | null>(null)
const deleteTarget = ref<AdminUserRow | null>(null)
const deleteOpen   = ref(false)

function askSuspend(u: AdminUserRow) {
  suspendReason.value = ''
  suspendTarget.value = u
}

async function confirmSuspend() {
  const u = suspendTarget.value
  if (!u) return
  suspendBusy.value = true
  try {
    await suspendUser(u.id, suspendReason.value || undefined)
    toast.success('Đã khoá tài khoản')
    suspendTarget.value = null
    load()
  } catch (e) {
    toast.error(extractError(e))
  } finally {
    suspendBusy.value = false
  }
}

async function onRestore(u: AdminUserRow) {
  try {
    await restoreUser(u.id)
    toast.success(u.status === 'deleted' ? 'Đã khôi phục tài khoản' : 'Đã mở khoá')
    load()
  } catch (e) { toast.error(extractError(e)) }
}

async function confirmReset() {
  const u = resetTarget.value
  if (!u) return
  resetTarget.value = null
  try {
    const res = await resetUserPassword(u.id)
    toast.success(res.message)
  } catch (e) { toast.error(extractError(e)) }
}

// Trước đây dùng chung 1 ref `deleteTarget` cho cả display + open flag → reka-ui
// AlertDialogAction emit `update:open(false)` NGAY khi bấm, cascade set
// deleteTarget=null TRƯỚC khi @click chạy → confirmDelete return sớm, không xoá.
// Fix: tách deleteOpen (bool điều khiển dialog) khỏi deleteTarget (user đang chờ xoá).
async function confirmDelete() {
  const u = deleteTarget.value
  if (!u) return
  deleteOpen.value = false
  try {
    await deleteUser(u.id)
    toast.success('Đã xoá tài khoản')
    deleteTarget.value = null
    load()
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
    <Card class="p-3 mb-4 flex-row flex-wrap gap-2 items-center shadow-xs">
      <div class="relative flex-1 min-w-[220px]">
        <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground pointer-events-none" />
        <Input v-model="filters.search" placeholder="Tìm tên hoặc email…" class="pl-9" />
      </div>
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
          <SelectItem value="deleted">Đã xoá</SelectItem>
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
    <Card class="py-0 gap-0 overflow-hidden shadow-xs">
      <div class="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow class="hover:bg-transparent">
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
              <TableHead class="text-right">Thao tác</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <template v-if="loading">
              <TableRow v-for="i in 5" :key="i">
                <TableCell v-for="j in 8" :key="j"><Skeleton class="h-4 w-full" /></TableCell>
              </TableRow>
            </template>
            <TableRow v-else-if="!rows.length" class="hover:bg-transparent">
              <TableCell colspan="8" class="p-0">
                <EmptyState :icon="SearchX" title="Không tìm thấy người dùng" hint="Thử đổi từ khoá tìm kiếm hoặc bỏ bớt bộ lọc." />
              </TableCell>
            </TableRow>
            <TableRow
              v-for="u in rows" v-else :key="u.id"
              class="cursor-pointer"
              @click="router.push(`/admin/users/${u.id}`)"
            >
              <TableCell>
                <div class="flex items-center gap-3">
                  <img v-if="u.avatar_url" :src="u.avatar_url" class="w-9 h-9 rounded-full object-cover" alt="" />
                  <div v-else class="w-9 h-9 rounded-full bg-calor-light text-calor-deep flex items-center justify-center text-xs font-semibold">
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
                <Badge variant="outline" class="gap-1.5 font-medium">
                  <span
                    class="w-1.5 h-1.5 rounded-full"
                    :class="{
                      'bg-emerald-500': u.status === 'active',
                      'bg-red-500':     u.status === 'suspended',
                      'bg-zinc-400':    u.status === 'deleted',
                    }"
                  />
                  {{ u.status === 'active' ? 'Active' : u.status === 'suspended' ? 'Bị khoá' : 'Đã xoá' }}
                </Badge>
              </TableCell>
              <TableCell class="text-right text-muted-foreground tabular-nums">{{ u.calorie_streak }}</TableCell>
              <TableCell class="text-right text-muted-foreground tabular-nums">{{ u.meal_logs_count }}</TableCell>
              <TableCell class="text-muted-foreground">{{ fmtDate(u.last_seen_at) }}</TableCell>
              <TableCell class="text-muted-foreground">{{ fmtDate(u.created_at) }}</TableCell>
              <TableCell class="text-right" @click.stop>
                <div class="inline-flex items-center gap-0.5">
                  <IconAction label="Xem chi tiết" tone="view" @click="router.push(`/admin/users/${u.id}`)"><Eye /></IconAction>
                  <!-- Không cho tự khoá/tự xoá tài khoản đang đăng nhập -->
                  <template v-if="u.status === 'active'">
                    <IconAction v-if="!isSelf(u)" label="Khoá tài khoản" tone="warn" @click="askSuspend(u)"><Lock /></IconAction>
                  </template>
                  <template v-else-if="u.status === 'suspended'">
                    <IconAction label="Mở khoá" tone="success" @click="onRestore(u)"><LockOpen /></IconAction>
                  </template>
                  <template v-else>
                    <!-- deleted: khôi phục soft-delete → clear deleted_at + status=active -->
                    <IconAction label="Khôi phục tài khoản" tone="success" @click="onRestore(u)"><LockOpen /></IconAction>
                  </template>
                  <IconAction v-if="u.status !== 'deleted'" label="Gửi email đặt lại mật khẩu" tone="edit" @click="resetTarget = u"><KeyRound /></IconAction>
                  <IconAction v-if="!isSelf(u) && u.status !== 'deleted'" label="Xoá tài khoản" tone="delete" @click="() => { deleteTarget = u; deleteOpen = true }"><Trash2 /></IconAction>
                </div>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>

      <!-- Pagination -->
      <div class="flex items-center justify-between px-4 py-3 border-t text-sm text-muted-foreground">
        <span>Tổng <span class="font-medium text-foreground">{{ meta.total }}</span> người dùng</span>
        <div class="flex items-center gap-1">
          <Button variant="outline" size="icon" class="h-8 w-8" :disabled="meta.current_page <= 1" @click="goPage(meta.current_page - 1)">
            <ChevronLeft class="w-4 h-4" />
          </Button>
          <span class="px-2 tabular-nums">Trang {{ meta.current_page }} / {{ meta.last_page }}</span>
          <Button variant="outline" size="icon" class="h-8 w-8" :disabled="meta.current_page >= meta.last_page" @click="goPage(meta.current_page + 1)">
            <ChevronRight class="w-4 h-4" />
          </Button>
        </div>
      </div>
    </Card>

    <!-- Modal khoá tài khoản (có lý do) -->
    <Dialog :open="!!suspendTarget" @update:open="(v: boolean) => { if (!v) suspendTarget = null }">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle class="flex items-center gap-2"><Lock class="w-4 h-4 text-amber-600" /> Khoá tài khoản</DialogTitle>
          <DialogDescription>
            "{{ suspendTarget?.name }}" sẽ không đăng nhập / gọi API được cho tới khi mở khoá.
          </DialogDescription>
        </DialogHeader>
        <div class="space-y-1.5">
          <Label for="suspend-reason">Lý do (tuỳ chọn)</Label>
          <Input id="suspend-reason" v-model="suspendReason" placeholder="Vd: spam, vi phạm điều khoản…" @keydown.enter="confirmSuspend" />
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="suspendBusy" @click="suspendTarget = null">Huỷ</Button>
          <Button class="bg-amber-600 hover:bg-amber-700 text-white" :disabled="suspendBusy" @click="confirmSuspend">
            <Loader2 v-if="suspendBusy" class="w-4 h-4 animate-spin" />
            Khoá tài khoản
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Confirm reset mật khẩu -->
    <AlertDialog :open="!!resetTarget" @update:open="(v: boolean) => { if (!v) resetTarget = null }">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle class="flex items-center gap-2"><KeyRound class="w-4 h-4 text-blue-600" /> Đặt lại mật khẩu?</AlertDialogTitle>
          <AlertDialogDescription>Hệ thống sẽ gửi email đặt lại mật khẩu tới {{ resetTarget?.email }}.</AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Huỷ</AlertDialogCancel>
          <AlertDialogAction @click="confirmReset">Gửi email</AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>

    <!-- Confirm xoá — tách deleteOpen khỏi deleteTarget để reka-ui không reset target trước khi confirmDelete kịp đọc -->
    <AlertDialog v-model:open="deleteOpen">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle class="flex items-center gap-2"><Trash2 class="w-4 h-4 text-destructive" /> Xoá tài khoản?</AlertDialogTitle>
          <AlertDialogDescription>
            Tài khoản "{{ deleteTarget?.name }}" sẽ bị xoá mềm — người dùng mất truy cập ngay nhưng dữ liệu có thể khôi phục.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Huỷ</AlertDialogCancel>
          <AlertDialogAction class="bg-destructive text-white hover:bg-destructive/90" @click="confirmDelete">Xoá</AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  </div>
</template>
