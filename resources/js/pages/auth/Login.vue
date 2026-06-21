<script setup lang="ts">
import CaloeyeCharacter from '@/components/caloeye/Character.vue'

const { login, loginWithGoogle, loginAsGuest, extractError } = useAuth()

const email = ref('')
const password = ref('')
const showPassword = ref(false)
const loading = ref(false)
const formError = ref('')
const errors = reactive({ email: '', password: '' })

const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function validate(): boolean {
  errors.email = ''
  errors.password = ''
  let ok = true
  if (!email.value) { errors.email = 'Vui lòng nhập email'; ok = false }
  else if (!emailRe.test(email.value)) { errors.email = 'Email không hợp lệ'; ok = false }
  if (!password.value) { errors.password = 'Vui lòng nhập mật khẩu'; ok = false }
  else if (password.value.length < 6) { errors.password = 'Mật khẩu tối thiểu 6 ký tự'; ok = false }
  return ok
}

async function handleLogin() {
  if (!validate()) return
  loading.value = true
  formError.value = ''
  try {
    await login(email.value, password.value)
  } catch (err) {
    formError.value = extractError(err)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex flex-col min-h-full px-6 py-5">
    <!-- Logo + Character (compact horizontal) -->
    <div class="flex items-center gap-3 mb-5 animate-fadeInUp" style="opacity:0">
      <CaloeyeCharacter mood="wave" :size="64" />
      <div>
        <div class="flex items-baseline gap-0.5">
          <span class="text-[26px] font-bold text-[#0C447C] tracking-tight">Calor</span>
          <span class="text-[26px] font-bold text-calor-green tracking-tight">Eye</span>
        </div>
        <p class="text-[12px] text-ios-gray leading-tight">Chào mừng bạn trở lại! 🌱</p>
      </div>
    </div>

    <!-- Social sign-in (icon only) -->
    <div class="flex gap-3 mb-4 animate-fadeInUp delay-1" style="opacity:0">
      <!-- Google -->
      <button
        class="flex-1 h-[48px] bg-white border border-ios-gray5 rounded-[14px] flex items-center justify-center gap-2 ios-press"
        @click="loginWithGoogle"
      >
        <svg viewBox="0 0 24 24" class="w-5 h-5 flex-shrink-0">
          <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
          <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
          <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
          <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
        </svg>
        <span class="text-[13px] font-semibold text-black">Google</span>
      </button>

      <!-- Apple (coming soon) -->
      <button
        disabled
        class="flex-1 h-[48px] bg-black/5 border border-ios-gray5 rounded-[14px] flex items-center justify-center gap-2 cursor-not-allowed"
      >
        <svg viewBox="0 0 24 24" class="w-5 h-5 flex-shrink-0" fill="#8E8E93">
          <path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/>
        </svg>
        <span class="text-[13px] font-semibold text-ios-gray">Apple</span>
      </button>
    </div>

    <!-- Divider -->
    <div class="flex items-center gap-3 mb-4 animate-fadeInUp delay-2" style="opacity:0">
      <div class="flex-1 h-px bg-ios-gray5"/>
      <span class="text-[12px] text-ios-gray">hoặc dùng email</span>
      <div class="flex-1 h-px bg-ios-gray5"/>
    </div>

    <!-- Email / password form -->
    <div class="flex flex-col gap-2.5 animate-fadeInUp delay-2" style="opacity:0">
      <!-- Email -->
      <div>
        <div
          class="bg-calor-light/50 rounded-[14px] px-4 py-3 border transition-colors"
          :class="errors.email ? 'border-red-400' : 'border-calor-mint/40'"
        >
          <label class="text-[10px] font-semibold text-calor-dark uppercase tracking-wide block mb-0.5">Email</label>
          <input
            v-model="email" type="email" placeholder="ten@email.com" autocomplete="email"
            class="w-full bg-transparent text-[15px] text-black placeholder-ios-gray3 outline-none"
            @input="errors.email = ''"
          />
        </div>
        <p v-if="errors.email" class="text-[11px] text-red-500 mt-1 px-1">{{ errors.email }}</p>
      </div>

      <!-- Password -->
      <div>
        <div
          class="bg-calor-light/50 rounded-[14px] px-4 py-3 border transition-colors"
          :class="errors.password ? 'border-red-400' : 'border-calor-mint/40'"
        >
          <label class="text-[10px] font-semibold text-calor-dark uppercase tracking-wide block mb-0.5">Mật khẩu</label>
          <div class="flex items-center">
            <input
              v-model="password" :type="showPassword ? 'text' : 'password'"
              placeholder="••••••••" autocomplete="current-password"
              class="flex-1 bg-transparent text-[15px] text-black placeholder-ios-gray3 outline-none"
              @input="errors.password = ''"
            />
            <button class="text-calor-dark ml-2 ios-press" @click="showPassword = !showPassword">
              <svg viewBox="0 0 24 24" class="w-4.5 h-4.5" fill="currentColor">
                <path v-if="showPassword" d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
                <path v-else d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46A11.804 11.804 0 001 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/>
              </svg>
            </button>
          </div>
        </div>
        <p v-if="errors.password" class="text-[11px] text-red-500 mt-1 px-1">{{ errors.password }}</p>
      </div>

      <div class="flex justify-end">
        <NuxtLink to="/auth/forgot-password" class="text-[13px] text-calor-green font-medium">
          Quên mật khẩu?
        </NuxtLink>
      </div>

      <!-- API error -->
      <div v-if="formError" class="bg-red-50 border border-red-200 rounded-[12px] px-3 py-2.5 flex items-start gap-2">
        <svg viewBox="0 0 24 24" class="w-4 h-4 text-red-500 flex-shrink-0 mt-0.5" fill="currentColor">
          <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
        </svg>
        <p class="text-[12px] text-red-600 leading-snug">{{ formError }}</p>
      </div>

      <!-- Submit -->
      <button
        class="w-full h-[50px] rounded-[14px] text-white font-semibold text-[16px] flex items-center justify-center ios-press transition-opacity"
        :class="loading ? 'bg-calor-green/70' : 'bg-calor-green'"
        :disabled="loading"
        @click="handleLogin"
      >
        <svg v-if="loading" class="w-5 h-5 animate-spin" viewBox="0 0 24 24" fill="none">
          <circle cx="12" cy="12" r="10" stroke="white" stroke-width="3" opacity="0.3"/>
          <path d="M12 2a10 10 0 0110 10" stroke="white" stroke-width="3" stroke-linecap="round"/>
        </svg>
        <span v-else>Đăng nhập</span>
      </button>
    </div>

    <!-- Bottom actions -->
    <div class="mt-auto pt-4 flex flex-col items-center gap-2 animate-fadeInUp delay-3" style="opacity:0">
      <button class="text-[13px] text-ios-gray ios-press" @click="loginAsGuest">
        Tiếp tục với tư cách Khách
      </button>
      <div class="flex gap-1">
        <span class="text-[13px] text-ios-gray">Chưa có tài khoản?</span>
        <NuxtLink to="/auth/register" class="text-[13px] text-calor-green font-semibold">Đăng ký</NuxtLink>
      </div>
    </div>
  </div>
</template>
