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
import { ArrowLeft, Loader2, Monitor, Smartphone, Globe } from 'lucide-vue-next'

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

async function onSuspend() {
  const reason = window.prompt('Nhập lý do khoá (tuỳ chọn):')
  if (reason === null) return
  try { await suspendUser(id, reason || undefined); toast.success('Đã khoá'); load() }
  catch (e) { toast.error(extractError(e)) }
}
async function onRestore() {
  try { await restoreUser(id); toast.success('Đã mở khoá'); load() }
  catch (e) { toast.error(extractError(e)) }
}
async function onReset() {
  if (!confirm('Gửi email đặt lại mật khẩu?')) return
  try { const r = await resetUserPassword(id); toast.success(r.message) }
  catch (e) { toast.error(extractError(e)) }
}
async function onDelete() {
  if (!confirm('Xoá tài khoản này?')) return
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

async function onRevokeSession(tokenId: number) {
  if (!confirm('Thu hồi phiên đăng nhập này? Thiết bị sẽ bị đăng xuất.')) return
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
            <Badge variant="secondary" :class="user.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">
              {{ user.status === 'active' ? 'Active' : 'Bị khoá' }}
            </Badge>
            <Badge variant="outline" class="capitalize">{{ user.provider }}</Badge>
          </div>
          <div class="text-sm text-muted-foreground">{{ user.email }}</div>
          <div v-if="user.status === 'suspended' && user.suspend_reason" class="text-xs text-destructive mt-1">Lý do khoá: {{ user.suspend_reason }}</div>
        </div>
      </Card>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <!-- Edit form -->
        <Card class="lg:col-span-2 gap-4">
          <CardHeader>
            <CardTitle class="text-base">Thông tin tài khoản</CardTitle>
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
            <CardHeader><CardTitle class="text-base">Hoạt động</CardTitle></CardHeader>
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
            <CardHeader><CardTitle class="text-base">Thông báo</CardTitle></CardHeader>
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
            <CardHeader><CardTitle class="text-base">Thiết bị đăng nhập</CardTitle></CardHeader>
            <CardContent>
              <p v-if="!user.sessions.length" class="text-sm text-muted-foreground">Không có phiên đăng nhập nào.</p>
              <ul v-else class="space-y-2">
                <li v-for="s in user.sessions" :key="s.id" class="flex items-center gap-2 text-sm">
                  <component :is="deviceIcon(s.device)" class="w-4 h-4 text-muted-foreground shrink-0" />
                  <div class="flex-1 min-w-0">
                    <div class="truncate">{{ s.device }}</div>
                    <div class="text-xs text-muted-foreground">Hoạt động: {{ fmtTime(s.last_used_at) }}</div>
                  </div>
                  <Button variant="outline" size="sm" class="text-destructive border-destructive/30 hover:bg-destructive/10 shrink-0 h-7" @click="onRevokeSession(s.id)">
                    Thu hồi
                  </Button>
                </li>
              </ul>
            </CardContent>
          </Card>

          <Card class="border-destructive/30 gap-3">
            <CardHeader><CardTitle class="text-base text-destructive">Vùng nguy hiểm</CardTitle></CardHeader>
            <CardContent class="space-y-2">
              <Button v-if="user.status === 'active'" variant="outline" class="w-full text-orange-600 border-orange-200 hover:bg-orange-50" :disabled="!!isSelf || user.role === 'admin'" @click="onSuspend">Khoá tài khoản</Button>
              <Button v-else variant="outline" class="w-full text-green-600 border-green-200 hover:bg-green-50" @click="onRestore">Mở khoá tài khoản</Button>
              <Button variant="outline" class="w-full" @click="onReset">Gửi reset mật khẩu</Button>
              <Button variant="outline" class="w-full text-destructive border-destructive/30 hover:bg-destructive/10" :disabled="!!isSelf || user.role === 'admin'" @click="onDelete">Xoá tài khoản</Button>
            </CardContent>
          </Card>
        </div>
      </div>
    </template>
  </div>
</template>
