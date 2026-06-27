<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAuth } from '@/composables/useAuth'
import { apiFetch } from '@/utils/api'
import type { AuthResponse } from '@/types/auth'

const router = useRouter()
const store = useAuthStore()
const { extractError } = useAuth()

const email = ref('')
const password = ref('')
const showPassword = ref(false)
const loading = ref(false)
const error = ref('')

onMounted(() => {
  document.body.classList.add('admin-mode')
  // Nếu đã đăng nhập sẵn bằng tài khoản admin → vào thẳng dashboard
  if (store.isLoggedIn && store.isAdmin) router.replace('/admin')
})
onUnmounted(() => document.body.classList.remove('admin-mode'))

async function submit() {
  error.value = ''
  if (!email.value || !password.value) {
    error.value = 'Vui lòng nhập email và mật khẩu'
    return
  }
  loading.value = true
  try {
    const res = await apiFetch<AuthResponse>('/auth/login', {
      method: 'POST',
      body: { email: email.value, password: password.value },
    })
    // Chỉ cho phép tài khoản admin vào khu quản trị
    if (res.user.role !== 'admin') {
      error.value = 'Tài khoản này không có quyền truy cập trang quản trị.'
      return
    }
    store.token = res.access_token
    store.user = res.user
    store.isGuest = false
    router.replace('/admin')
  } catch (e) {
    error.value = extractError(e) || 'Đăng nhập thất bại'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-dvh flex items-center justify-center bg-gradient-to-br from-[#0C4D3D] via-[#0F6E56] to-[#0C447C] px-4">
    <div class="w-full max-w-sm">
      <div class="text-center mb-6">
        <div class="inline-flex items-center gap-2 text-white">
          <span class="text-2xl font-bold">CaloEye</span>
          <span class="text-xs px-2 py-0.5 rounded bg-white/15 text-calor-mint font-semibold">Admin</span>
        </div>
        <p class="text-white/60 text-sm mt-1">Khu vực quản trị</p>
      </div>

      <div class="bg-white rounded-2xl shadow-2xl p-6">
        <h1 class="text-lg font-bold text-gray-900 mb-1">Đăng nhập quản trị</h1>
        <p class="text-sm text-gray-400 mb-5">Chỉ dành cho tài khoản có quyền admin</p>

        <form @submit.prevent="submit" class="space-y-4">
          <label class="block">
            <span class="text-xs font-medium text-gray-500">Email</span>
            <input
              v-model="email" type="email" autocomplete="username" autofocus
              class="mt-1 w-full px-3 py-2.5 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-calor-green/40"
              placeholder="admin@example.com"
            />
          </label>

          <label class="block">
            <span class="text-xs font-medium text-gray-500">Mật khẩu</span>
            <div class="mt-1 relative">
              <input
                v-model="password" :type="showPassword ? 'text' : 'password'" autocomplete="current-password"
                class="w-full px-3 py-2.5 pr-10 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-calor-green/40"
                placeholder="••••••••"
              />
              <button type="button" tabindex="-1"
                      class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 text-xs"
                      @click="showPassword = !showPassword">
                {{ showPassword ? 'Ẩn' : 'Hiện' }}
              </button>
            </div>
          </label>

          <p v-if="error" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ error }}</p>

          <button
            type="submit" :disabled="loading"
            class="w-full py-2.5 bg-calor-green text-white text-sm font-semibold rounded-lg hover:bg-calor-dark disabled:opacity-60 transition-colors"
          >
            {{ loading ? 'Đang đăng nhập…' : 'Đăng nhập' }}
          </button>
        </form>
      </div>

      <p class="text-center text-white/40 text-xs mt-5">
        © {{ new Date().getFullYear() }} CaloEye — Admin Console
      </p>
    </div>
  </div>
</template>
