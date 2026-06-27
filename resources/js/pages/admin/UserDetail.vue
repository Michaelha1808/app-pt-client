<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useAdmin } from '@/composables/useAdmin'
import { useAuth } from '@/composables/useAuth'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import type { AdminUserDetail } from '@/types/admin'

const route = useRoute()
const router = useRouter()
const { fetchUser, updateUser, suspendUser, restoreUser, resetUserPassword, deleteUser } = useAdmin()
const { extractError } = useAuth()
const { user: me } = storeToRefs(useAuthStore())
const toast = useToast()

const id = route.params.id as string
const user = ref<AdminUserDetail | null>(null)
const loading = ref(true)
const saving = ref(false)

const form = ref({ name: '', email: '', role: 'user' as 'user' | 'admin', calorie_goal: 2000, birth_year: null as number | null, gender: '' as string, height_cm: null as number | null, weight_kg: null as number | null })

const isSelf = computed(() => me.value && user.value && String(me.value.id) === String(user.value.id))

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
    <button class="mb-4 text-sm text-gray-500 hover:text-gray-800 flex items-center gap-1" @click="router.push('/admin/users')">
      ← Quay lại danh sách
    </button>

    <div v-if="loading" class="text-gray-400 py-10 text-center">Đang tải…</div>

    <template v-else-if="user">
      <!-- Header -->
      <div class="bg-white rounded-xl border border-gray-200 p-5 mb-4 flex items-center gap-4">
        <img v-if="user.avatar_url" :src="user.avatar_url" class="w-16 h-16 rounded-full object-cover" alt="" />
        <div v-else class="w-16 h-16 rounded-full bg-calor-light text-calor-deep flex items-center justify-center text-xl font-bold">
          {{ user.name.charAt(0).toUpperCase() }}
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <h1 class="text-lg font-bold text-gray-900">{{ user.name }}</h1>
            <span class="text-xs px-2 py-0.5 rounded-full" :class="user.role === 'admin' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600'">{{ user.role }}</span>
            <span class="text-xs px-2 py-0.5 rounded-full" :class="user.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">{{ user.status === 'active' ? 'Active' : 'Bị khoá' }}</span>
            <span class="text-xs px-2 py-0.5 rounded-full bg-blue-50 text-blue-600 capitalize">{{ user.provider }}</span>
          </div>
          <div class="text-sm text-gray-400">{{ user.email }}</div>
          <div v-if="user.status === 'suspended' && user.suspend_reason" class="text-xs text-red-500 mt-1">Lý do khoá: {{ user.suspend_reason }}</div>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <!-- Edit form -->
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-5">
          <h2 class="font-semibold text-gray-800 mb-4">Thông tin tài khoản</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="block">
              <span class="text-xs text-gray-500">Tên</span>
              <input v-model="form.name" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg" />
            </label>
            <label class="block">
              <span class="text-xs text-gray-500">Email</span>
              <input v-model="form.email" type="email" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg" />
            </label>
            <label class="block">
              <span class="text-xs text-gray-500">Vai trò</span>
              <select v-model="form.role" :disabled="isSelf" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg disabled:bg-gray-100">
                <option value="user">User</option>
                <option value="admin">Admin</option>
              </select>
              <span v-if="isSelf" class="text-[11px] text-gray-400">Không thể đổi vai trò của chính bạn</span>
            </label>
            <label class="block">
              <span class="text-xs text-gray-500">Mục tiêu calo</span>
              <input v-model.number="form.calorie_goal" type="number" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg" />
            </label>
            <label class="block">
              <span class="text-xs text-gray-500">Năm sinh</span>
              <input v-model.number="form.birth_year" type="number" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg" />
            </label>
            <label class="block">
              <span class="text-xs text-gray-500">Giới tính</span>
              <select v-model="form.gender" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                <option value="">—</option>
                <option value="male">Nam</option>
                <option value="female">Nữ</option>
                <option value="other">Khác</option>
              </select>
            </label>
            <label class="block">
              <span class="text-xs text-gray-500">Chiều cao (cm)</span>
              <input v-model.number="form.height_cm" type="number" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg" />
            </label>
            <label class="block">
              <span class="text-xs text-gray-500">Cân nặng (kg)</span>
              <input v-model.number="form.weight_kg" type="number" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg" />
            </label>
          </div>
          <button class="mt-5 px-4 py-2 bg-calor-green text-white text-sm font-semibold rounded-lg hover:bg-calor-dark disabled:opacity-60" :disabled="saving" @click="save">
            {{ saving ? 'Đang lưu…' : 'Lưu thay đổi' }}
          </button>
        </div>

        <!-- Side: stats + danger -->
        <div class="space-y-4">
          <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="font-semibold text-gray-800 mb-3">Hoạt động</h2>
            <div class="grid grid-cols-2 gap-3">
              <div v-for="s in statItems" :key="s.label" class="bg-gray-50 rounded-lg p-3">
                <div class="text-lg font-bold text-gray-800">{{ s.value }}</div>
                <div class="text-xs text-gray-400">{{ s.label }}</div>
              </div>
            </div>
          </div>

          <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="font-semibold text-gray-800 mb-3">Thông báo</h2>
            <ul class="text-sm space-y-1.5">
              <li class="flex justify-between"><span class="text-gray-500">Buổi sáng</span><span>{{ user.notify.morning ? '✅' : '—' }}</span></li>
              <li class="flex justify-between"><span class="text-gray-500">Buổi trưa</span><span>{{ user.notify.midday ? '✅' : '—' }}</span></li>
              <li class="flex justify-between"><span class="text-gray-500">Buổi tối</span><span>{{ user.notify.evening ? '✅' : '—' }}</span></li>
              <li class="flex justify-between"><span class="text-gray-500">Email re-engage</span><span>{{ user.notify.email_reengagement ? '✅' : '—' }}</span></li>
            </ul>
          </div>

          <div class="bg-white rounded-xl border border-red-200 p-5">
            <h2 class="font-semibold text-red-600 mb-3">Vùng nguy hiểm</h2>
            <div class="space-y-2">
              <button v-if="user.status === 'active'" class="w-full py-2 text-sm font-medium text-orange-600 border border-orange-200 rounded-lg hover:bg-orange-50 disabled:opacity-40" :disabled="!!isSelf || user.role === 'admin'" @click="onSuspend">Khoá tài khoản</button>
              <button v-else class="w-full py-2 text-sm font-medium text-green-600 border border-green-200 rounded-lg hover:bg-green-50" @click="onRestore">Mở khoá tài khoản</button>
              <button class="w-full py-2 text-sm font-medium text-gray-700 border border-gray-200 rounded-lg hover:bg-gray-50" @click="onReset">Gửi reset mật khẩu</button>
              <button class="w-full py-2 text-sm font-medium text-red-600 border border-red-200 rounded-lg hover:bg-red-50 disabled:opacity-40" :disabled="!!isSelf || user.role === 'admin'" @click="onDelete">Xoá tài khoản</button>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
