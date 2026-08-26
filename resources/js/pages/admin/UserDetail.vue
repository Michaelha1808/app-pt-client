<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useAdmin } from '@/composables/useAdmin'
import { useAuth } from '@/composables/useAuth'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import type { AdminUserDetail } from '@/types/admin'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import {
  AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
  AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import {
  Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from '@/components/ui/dialog'
import {
  ArrowLeft, Loader2, Monitor, Smartphone, Globe, UserCog, Activity,
  BellRing, MonitorSmartphone, ShieldAlert, Lock, LockOpen, KeyRound, Trash2, LogOut,
} from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()
const { fetchUser, updateUser, suspendUser, restoreUser, resetUserPassword, deleteUser, revokeUserSession } = useAdmin()
const { extractError } = useAuth()
const { user: me } = storeToRefs(useAuthStore())
const toast = useToast()

const id = route.params.id as string
const user = ref<AdminUserDetail | null>(null)
const loading = ref(true)
const saving = ref(false)

const form = ref({ name: '', email: '', role: 'user' as 'user' | 'admin', calorie_goal: 2000, birth_year: null as number | null, gender: '' as string, height_cm: null as number | null, weight_kg: null as number | null })

const isSelf = computed(() => me.value && user.value && String(me.value.id) === String(user.value.id))

// shadcn Select không nhận value rỗng → sentinel 'none' cho "chưa chọn giới tính"
const genderModel = computed({
  get: () => form.value.gender || 'none',
  set: (v: string) => { form.value.gender = v === 'none' ? '' : v },
})

async function load() {
  loading.value = true
  try {
    const u = await fetchUser(id)
    user.value = u
    form.value = {
      name: u.name, email: u.email, role: u.role, calorie_goal: u.calorie_goal ?? 2000,
      birth_year: u.birth_year, gender: u.gender ?? '', height_cm: u.height_cm, weight_kg: u.weight_kg,
    }
  } catch (e) {
    toast.error(extractError(e) || 'Không tải được người dùng')
    router.push('/admin/users')
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    const payload: Record<string, unknown> = {
      name: form.value.name, email: form.value.email,
      calorie_goal: form.value.calorie_goal,
    }
    if (form.value.birth_year) payload.birth_year = form.value.birth_year
    if (form.value.gender) payload.gender = form.value.gender
    if (form.value.height_cm) payload.height_cm = form.value.height_cm
    if (form.value.weight_kg) payload.weight_kg = form.value.weight_kg
    if (!isSelf.value) payload.role = form.value.role
    user.value = await updateUser(id, payload)
    toast.success('Đã lưu thay đổi')
  } catch (e) {
    toast.error(extractError(e))
  } finally {
    saving.value = false
  }
}

// Confirm bằng dialog thay window.prompt/confirm
const suspendOpen = ref(false)
const suspendReason = ref('')
const suspendBusy = ref(false)
const resetOpen = ref(false)
const deleteOpen = ref(false)
const revokeId = ref<number | null>(null)

async function confirmSuspend() {
  suspendBusy.value = true
  try {
    await suspendUser(id, suspendReason.value || undefined)
    toast.success('Đã khoá'); suspendOpen.value = false; load()
  } catch (e) { toast.error(extractError(e)) }
  finally { suspendBusy.value = false }
}
async function onRestore() {
  try {
    await restoreUser(id)
    toast.success(user.value?.status === 'deleted' ? 'Đã khôi phục tài khoản' : 'Đã mở khoá')
    load()
  }
  catch (e) { toast.error(extractError(e)) }
}
async function confirmReset() {
  resetOpen.value = false
  try { const r = await resetUserPassword(id); toast.success(r.message) }
  catch (e) { toast.error(extractError(e)) }
}
async function confirmDelete() {
  deleteOpen.value = false
  try { await deleteUser(id); toast.success('Đã xoá'); router.push('/admin/users') }
  catch (e) { toast.error(extractError(e)) }
}

function fmtTime(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  const diff = Date.now() - d.getTime()
  const mins = Math.floor(diff / 60000)
  if (mins < 1) return 'vừa xong'
  if (mins < 60) return `${mins} phút trước`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24) return `${hrs} giờ trước`
  return d.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function deviceIcon(device: string) {
  if (/iPhone|iPad|iPod|Android/i.test(device)) return Smartphone
  if (/Windows|macOS|Linux|ChromeOS/i.test(device)) return Monitor
  return Globe
}

async function confirmRevokeSession() {
  const tokenId = revokeId.value
  if (tokenId === null) return
  revokeId.value = null
  try {
    await revokeUserSession(id, tokenId)
    if (user.value) user.value.sessions = user.value.sessions.filter(s => s.id !== tokenId)
    toast.success('Đã thu hồi phiên')
  } catch (e) {
    toast.error(extractError(e))
  }
}

const statItems = computed(() => {
  const s = user.value?.stats
  if (!s) return []
  return [
    { label: 'Meal logs', value: s.meal_logs },
    { label: 'Water logs', value: s.water_logs },
    { label: 'Kế hoạch AI', value: s.plans },
    { label: 'Passkeys', value: s.passkeys },
  ]
})

onMounted(load)
</script>

<template>
  <div>
    <Button variant="ghost" size="sm" class="mb-4 -ml-2 text-muted-foreground" @click="router.push('/admin/users')">
      <ArrowLeft class="w-4 h-4" /> Quay lại danh sách
    </Button>

    <div v-if="loading" class="space-y-4">
      <Skeleton class="h-24 rounded-xl" />
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <Skeleton class="lg:col-span-2 h-80 rounded-xl" />
        <Skeleton class="h-80 rounded-xl" />
      </div>
    </div>

    <template v-else-if="user">
      <!-- Header -->
      <Card class="p-5 mb-4 flex-row items-center gap-4">
        <img v-if="user.avatar_url" :src="user.avatar_url" class="w-16 h-16 rounded-full object-cover" alt="" />
        <div v-else class="w-16 h-16 rounded-full bg-calor-light text-calor-deep flex items-center justify-center text-xl font-bold">
          {{ user.name.charAt(0).toUpperCase() }}
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <h1 class="text-lg font-bold">{{ user.name }}</h1>
            <Badge :variant="user.role === 'admin' ? 'default' : 'secondary'" class="capitalize">{{ user.role }}</Badge>
            <Badge variant="outline" class="gap-1.5 font-medium">
              <span
                class="w-1.5 h-1.5 rounded-full"
                :class="{
                  'bg-emerald-500': user.status === 'active',
                  'bg-red-500':     user.status === 'suspended',
                  'bg-zinc-400':    user.status === 'deleted',
                }"
              />
              {{ user.status === 'active' ? 'Active' : user.status === 'suspended' ? 'Bị khoá' : 'Đã xoá' }}
            </Badge>
            <Badge variant="outline" class="capitalize">{{ user.provider }}</Badge>
          </div>
          <div class="text-sm text-muted-foreground">{{ user.email }}</div>
          <div v-if="user.status === 'suspended' && user.suspend_reason" class="text-xs text-destructive mt-1">Lý do khoá: {{ user.suspend_reason }}</div>
          <div v-else-if="user.status === 'deleted' && user.deleted_at" class="text-xs text-muted-foreground mt-1">Xoá lúc: {{ fmtTime(user.deleted_at) }}</div>
        </div>
      </Card>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <!-- Edit form -->
        <Card class="lg:col-span-2 gap-4">
          <CardHeader>
            <CardTitle class="text-base flex items-center gap-2"><UserCog class="w-4 h-4 text-primary" /> Thông tin tài khoản</CardTitle>
          </CardHeader>
          <CardContent>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="space-y-1.5">
                <Label>Tên</Label>
                <Input v-model="form.name" />
              </div>
              <div class="space-y-1.5">
                <Label>Email</Label>
                <Input v-model="form.email" type="email" />
              </div>
              <div class="space-y-1.5">
                <Label>Vai trò</Label>
                <Select v-model="form.role" :disabled="!!isSelf">
                  <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="user">User</SelectItem>
                    <SelectItem value="admin">Admin</SelectItem>
                  </SelectContent>
                </Select>
                <span v-if="isSelf" class="text-[11px] text-muted-foreground">Không thể đổi vai trò của chính bạn</span>
              </div>
              <div class="space-y-1.5">
                <Label>Mục tiêu calo</Label>
                <Input v-model.number="form.calorie_goal" type="number" />
              </div>
              <div class="space-y-1.5">
                <Label>Năm sinh</Label>
                <Input v-model.number="form.birth_year" type="number" />
              </div>
              <div class="space-y-1.5">
                <Label>Giới tính</Label>
                <Select v-model="genderModel">
                  <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">—</SelectItem>
                    <SelectItem value="male">Nam</SelectItem>
                    <SelectItem value="female">Nữ</SelectItem>
                    <SelectItem value="other">Khác</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div class="space-y-1.5">
                <Label>Chiều cao (cm)</Label>
                <Input v-model.number="form.height_cm" type="number" />
              </div>
              <div class="space-y-1.5">
                <Label>Cân nặng (kg)</Label>
                <Input v-model.number="form.weight_kg" type="number" />
              </div>
            </div>
            <Button class="mt-5" :disabled="saving" @click="save">
              <Loader2 v-if="saving" class="w-4 h-4 animate-spin" />
              {{ saving ? 'Đang lưu…' : 'Lưu thay đổi' }}
            </Button>
          </CardContent>
        </Card>

        <!-- Side: stats + danger -->
        <div class="space-y-4">
          <Card class="gap-3">
            <CardHeader><CardTitle class="text-base flex items-center gap-2"><Activity class="w-4 h-4 text-primary" /> Hoạt động</CardTitle></CardHeader>
            <CardContent>
              <div class="grid grid-cols-2 gap-3">
                <div v-for="s in statItems" :key="s.label" class="bg-muted rounded-lg p-3">
                  <div class="text-lg font-bold">{{ s.value }}</div>
                  <div class="text-xs text-muted-foreground">{{ s.label }}</div>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card class="gap-3">
            <CardHeader><CardTitle class="text-base flex items-center gap-2"><BellRing class="w-4 h-4 text-primary" /> Thông báo</CardTitle></CardHeader>
            <CardContent>
              <ul class="text-sm space-y-1.5">
                <li class="flex justify-between"><span class="text-muted-foreground">Buổi sáng</span><span>{{ user.notify.morning ? '✅' : '—' }}</span></li>
                <li class="flex justify-between"><span class="text-muted-foreground">Buổi trưa</span><span>{{ user.notify.midday ? '✅' : '—' }}</span></li>
                <li class="flex justify-between"><span class="text-muted-foreground">Buổi tối</span><span>{{ user.notify.evening ? '✅' : '—' }}</span></li>
                <li class="flex justify-between"><span class="text-muted-foreground">Email re-engage</span><span>{{ user.notify.email_reengagement ? '✅' : '—' }}</span></li>
              </ul>
            </CardContent>
          </Card>

          <Card class="gap-3">
            <CardHeader><CardTitle class="text-base flex items-center gap-2"><MonitorSmartphone class="w-4 h-4 text-primary" /> Thiết bị đăng nhập</CardTitle></CardHeader>
            <CardContent>
              <p v-if="!user.sessions.length" class="text-sm text-muted-foreground">Không có phiên đăng nhập nào.</p>
              <ul v-else class="space-y-2">
                <li v-for="s in user.sessions" :key="s.id" class="flex items-center gap-2 text-sm">
                  <component :is="deviceIcon(s.device)" class="w-4 h-4 text-muted-foreground shrink-0" />
                  <div class="flex-1 min-w-0">
                    <div class="truncate">{{ s.device }}</div>
                    <div class="text-xs text-muted-foreground">Hoạt động: {{ fmtTime(s.last_used_at) }}</div>
                  </div>
                  <Button variant="outline" size="sm" class="text-destructive border-destructive/30 hover:bg-destructive/10 shrink-0 h-7" @click="revokeId = s.id">
                    <LogOut class="w-3.5 h-3.5" /> Thu hồi
                  </Button>
                </li>
              </ul>
            </CardContent>
          </Card>

          <Card class="border-destructive/30 gap-3">
            <CardHeader><CardTitle class="text-base text-destructive flex items-center gap-2"><ShieldAlert class="w-4 h-4" /> Vùng nguy hiểm</CardTitle></CardHeader>
            <CardContent class="space-y-2">
              <Button v-if="user.status === 'active'" variant="outline" class="w-full text-amber-600 border-amber-200 hover:bg-amber-50 hover:text-amber-700" :disabled="!!isSelf || user.role === 'admin'" @click="suspendReason = ''; suspendOpen = true">
                <Lock class="w-4 h-4" /> Khoá tài khoản
              </Button>
              <Button v-else variant="outline" class="w-full text-emerald-600 border-emerald-200 hover:bg-emerald-50 hover:text-emerald-700" @click="onRestore">
                <LockOpen class="w-4 h-4" />
                {{ user.status === 'deleted' ? 'Khôi phục tài khoản' : 'Mở khoá tài khoản' }}
              </Button>
              <Button v-if="user.status !== 'deleted'" variant="outline" class="w-full" @click="resetOpen = true">
                <KeyRound class="w-4 h-4" /> Gửi reset mật khẩu
              </Button>
              <Button v-if="user.status !== 'deleted'" variant="outline" class="w-full text-destructive border-destructive/30 hover:bg-destructive/10 hover:text-destructive" :disabled="!!isSelf || user.role === 'admin'" @click="deleteOpen = true">
                <Trash2 class="w-4 h-4" /> Xoá tài khoản
              </Button>
            </CardContent>
          </Card>
        </div>
      </div>
    </template>

    <!-- Modal khoá tài khoản -->
    <Dialog v-model:open="suspendOpen">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle class="flex items-center gap-2"><Lock class="w-4 h-4 text-amber-600" /> Khoá tài khoản</DialogTitle>
          <DialogDescription>Người dùng sẽ không đăng nhập / gọi API được cho tới khi mở khoá.</DialogDescription>
        </DialogHeader>
        <div class="space-y-1.5">
          <Label for="ud-suspend-reason">Lý do (tuỳ chọn)</Label>
          <Input id="ud-suspend-reason" v-model="suspendReason" placeholder="Vd: spam, vi phạm điều khoản…" @keydown.enter="confirmSuspend" />
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="suspendBusy" @click="suspendOpen = false">Huỷ</Button>
          <Button class="bg-amber-600 hover:bg-amber-700 text-white" :disabled="suspendBusy" @click="confirmSuspend">
            <Loader2 v-if="suspendBusy" class="w-4 h-4 animate-spin" />
            Khoá tài khoản
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Confirm reset mật khẩu -->
    <AlertDialog v-model:open="resetOpen">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle class="flex items-center gap-2"><KeyRound class="w-4 h-4 text-blue-600" /> Đặt lại mật khẩu?</AlertDialogTitle>
          <AlertDialogDescription>Hệ thống sẽ gửi email đặt lại mật khẩu tới {{ user?.email }}.</AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Huỷ</AlertDialogCancel>
          <AlertDialogAction @click="confirmReset">Gửi email</AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>

    <!-- Confirm xoá tài khoản -->
    <AlertDialog v-model:open="deleteOpen">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle class="flex items-center gap-2"><Trash2 class="w-4 h-4 text-destructive" /> Xoá tài khoản?</AlertDialogTitle>
          <AlertDialogDescription>Tài khoản sẽ bị xoá mềm — người dùng mất truy cập ngay nhưng dữ liệu có thể khôi phục.</AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Huỷ</AlertDialogCancel>
          <AlertDialogAction class="bg-destructive text-white hover:bg-destructive/90" @click="confirmDelete">Xoá</AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>

    <!-- Confirm thu hồi phiên -->
    <AlertDialog :open="revokeId !== null" @update:open="(v: boolean) => { if (!v) revokeId = null }">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle class="flex items-center gap-2"><LogOut class="w-4 h-4 text-destructive" /> Thu hồi phiên đăng nhập?</AlertDialogTitle>
          <AlertDialogDescription>Thiết bị này sẽ bị đăng xuất ngay lập tức.</AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Huỷ</AlertDialogCancel>
          <AlertDialogAction class="bg-destructive text-white hover:bg-destructive/90" @click="confirmRevokeSession">Thu hồi</AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  </div>
</template>
