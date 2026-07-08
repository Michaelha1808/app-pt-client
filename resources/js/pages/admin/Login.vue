<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAuth } from '@/composables/useAuth'
import { apiFetch } from '@/utils/api'
import type { AuthResponse } from '@/types/auth'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Eye, EyeOff, Loader2 } from 'lucide-vue-next'

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

      <Card class="shadow-2xl">
        <CardHeader>
          <CardTitle>Đăng nhập quản trị</CardTitle>
          <CardDescription>Chỉ dành cho tài khoản có quyền admin</CardDescription>
        </CardHeader>
        <CardContent>
          <form class="space-y-4" @submit.prevent="submit">
            <div class="space-y-1.5">
              <Label for="admin-email">Email</Label>
              <Input
                id="admin-email" v-model="email" type="email"
                autocomplete="username" autofocus placeholder="admin@example.com"
              />
            </div>

            <div class="space-y-1.5">
              <Label for="admin-password">Mật khẩu</Label>
              <div class="relative">
                <Input
                  id="admin-password" v-model="password" :type="showPassword ? 'text' : 'password'"
                  autocomplete="current-password" placeholder="••••••••" class="pr-10"
                />
                <button
                  type="button" tabindex="-1"
                  class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                  @click="showPassword = !showPassword"
                >
                  <EyeOff v-if="showPassword" class="w-4 h-4" />
                  <Eye v-else class="w-4 h-4" />
                </button>
              </div>
            </div>

            <p v-if="error" class="text-sm text-destructive bg-destructive/10 rounded-lg px-3 py-2">{{ error }}</p>

            <Button type="submit" class="w-full" :disabled="loading">
              <Loader2 v-if="loading" class="w-4 h-4 animate-spin" />
              {{ loading ? 'Đang đăng nhập…' : 'Đăng nhập' }}
            </Button>
          </form>
        </CardContent>
      </Card>

      <p class="text-center text-white/40 text-xs mt-5">
        © {{ new Date().getFullYear() }} CaloEye — Admin Console
      </p>
    </div>
  </div>
</template>
