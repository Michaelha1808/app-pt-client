<script setup lang="ts">
import CaloeyeCharacter from '@/components/caloeye/Character.vue'

const { login, loginWithGoogle, loginWithFacebook, loginAsGuest, extractError } = useAuth()
const { registered: bioEnabled, loginWithPasskey } = usePasskey()
const router = useRouter()

const email = ref('')
const password = ref('')
const showPassword = ref(false)
const loading = ref(false)
const bioLoading = ref(false)
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

// Đăng nhập bằng passkey (vân tay/Face ID) — server WebAuthn verify → cấp phiên
async function handleBiometricLogin() {
  bioLoading.value = true
  formError.value = ''
  try {
    await loginWithPasskey()
    const dest = sessionStorage.getItem('pending_redirect')
    sessionStorage.removeItem('pending_redirect')
    router.push(dest && !dest.startsWith('/auth') ? dest : '/home')
  } catch (err) {
    // Lỗi API có data.detail; lỗi WebAuthn (huỷ/không hỗ trợ) có message thân thiện
    formError.value = (err as any)?.data?.detail ?? (err as any)?.message ?? extractError(err)
  } finally {
    bioLoading.value = false
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

      <!-- Submit + nút Face ID (chỉ icon, cạnh nút đăng nhập) -->
      <div class="flex items-center gap-2.5">
        <button
          class="flex-1 h-[50px] rounded-[14px] text-white font-semibold text-[16px] flex items-center justify-center ios-press transition-opacity"
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

        <!-- Đăng nhập bằng vân tay / Face ID — chỉ icon (hiện nếu máy đã đăng ký) -->
        <button
          v-if="bioEnabled"
          aria-label="Đăng nhập bằng vân tay / Face ID"
          class="w-[50px] h-[50px] flex-shrink-0 rounded-[14px] bg-white border border-calor-mint/50 flex items-center justify-center ios-press transition-opacity"
          :class="bioLoading ? 'opacity-60' : ''"
          :disabled="bioLoading"
          @click="handleBiometricLogin"
        >
          <svg v-if="bioLoading" class="w-6 h-6 animate-spin text-calor-green" viewBox="0 0 24 24" fill="none">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.3"/>
            <path d="M12 2a10 10 0 0110 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          </svg>
          <svg v-else viewBox="0 0 24 24" class="w-6 h-6 text-calor-green" fill="currentColor">
            <path d="M17.81 4.47c-.08 0-.16-.02-.23-.06C15.66 3.42 14 3 12.01 3c-1.98 0-3.86.47-5.57 1.41-.24.13-.54.04-.68-.2-.13-.24-.04-.55.2-.68C7.82 2.52 9.86 2 12.01 2c2.13 0 3.99.47 6.03 1.52.25.13.34.43.21.67-.09.18-.26.28-.44.28zM3.5 9.72c-.1 0-.2-.03-.29-.09-.23-.16-.28-.47-.12-.7.99-1.4 2.25-2.5 3.75-3.27C9.98 4.04 14 4.03 17.15 5.65c1.5.77 2.76 1.86 3.75 3.25.16.22.11.54-.12.7-.23.16-.54.11-.7-.12-.9-1.26-2.04-2.25-3.39-2.94-2.87-1.47-6.54-1.47-9.4.01-1.36.7-2.4 1.7-3.3 2.96-.08.14-.23.21-.39.21zm6.25 12.07c-.13 0-.26-.05-.35-.15-.87-.87-1.34-1.43-2.01-2.64-.69-1.23-1.05-2.73-1.05-4.34 0-2.97 2.54-5.39 5.66-5.39s5.66 2.42 5.66 5.39c0 .28-.22.5-.5.5s-.5-.22-.5-.5c0-2.42-2.09-4.39-4.66-4.39s-4.66 1.97-4.66 4.39c0 1.44.32 2.77.93 3.85.64 1.15 1.08 1.64 1.85 2.42.19.2.19.51 0 .71-.11.1-.24.14-.37.14zm7.17-1.85c-1.19 0-2.24-.3-3.1-.89-1.49-1.01-2.38-2.65-2.38-4.39 0-.28.22-.5.5-.5s.5.22.5.5c0 1.41.72 2.74 1.94 3.56.71.49 1.54.71 2.54.71.24 0 .64-.03 1.04-.1.27-.05.53.13.58.41.05.27-.13.53-.41.58-.57.11-1.07.12-1.21.12zM14.91 22c-.04 0-.09-.01-.13-.02-1.59-.44-2.63-1.03-3.72-2.1-1.4-1.39-2.17-3.24-2.17-5.22 0-1.62 1.38-2.94 3.08-2.94s3.08 1.32 3.08 2.94c0 1.07.93 1.94 2.08 1.94s2.08-.87 2.08-1.94c0-3.77-3.25-6.83-7.25-6.83-2.84 0-5.44 1.58-6.61 4.03-.39.81-.59 1.76-.59 2.8 0 .78.07 2.01.67 3.61.1.26-.03.55-.29.64-.26.1-.55-.04-.64-.29-.49-1.31-.73-2.61-.73-3.96 0-1.2.23-2.29.68-3.24 1.33-2.79 4.28-4.6 7.51-4.6 4.55 0 8.25 3.51 8.25 7.83 0 1.62-1.38 2.94-3.08 2.94s-3.08-1.32-3.08-2.94c0-1.07-.93-1.94-2.08-1.94s-2.08.87-2.08 1.94c0 1.71.66 3.31 1.87 4.51.95.94 1.86 1.46 3.27 1.85.27.07.42.35.35.61-.06.23-.26.38-.48.38z"/>
          </svg>
        </button>
      </div>
    </div>

    <!-- Divider -->
    <div class="flex items-center gap-3 mt-5 mb-4 animate-fadeInUp delay-2" style="opacity:0">
      <div class="flex-1 h-px bg-ios-gray5"/>
      <span class="text-[12px] text-ios-gray">hoặc đăng nhập với</span>
      <div class="flex-1 h-px bg-ios-gray5"/>
    </div>

    <!-- Social sign-in (round icon only) -->
    <div class="flex justify-center gap-4 animate-fadeInUp delay-2" style="opacity:0">
      <button
        aria-label="Đăng nhập với Google"
        class="w-12 h-12 rounded-full bg-white border border-ios-gray5 shadow-sm flex items-center justify-center ios-press"
        @click="loginWithGoogle"
      >
        <svg viewBox="0 0 24 24" class="w-6 h-6">
          <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
          <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
          <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
          <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
        </svg>
      </button>

      <button
        aria-label="Đăng nhập với Facebook"
        class="w-12 h-12 rounded-full bg-[#1877F2] shadow-sm flex items-center justify-center ios-press"
        @click="loginWithFacebook"
      >
        <svg viewBox="0 0 24 24" class="w-6 h-6" fill="white">
          <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073c0 6.024 4.388 11.018 10.125 11.927v-8.437H7.078v-3.49h3.047V9.412c0-3.017 1.791-4.683 4.533-4.683 1.313 0 2.686.235 2.686.235v2.971h-1.513c-1.491 0-1.956.93-1.956 1.886v2.262h3.328l-.532 3.49h-2.796V24C19.612 23.091 24 18.097 24 12.073z"/>
        </svg>
      </button>

      <button
        disabled
        aria-label="Đăng nhập với Apple (chưa hỗ trợ)"
        class="w-12 h-12 rounded-full bg-black shadow-sm flex items-center justify-center opacity-40 cursor-not-allowed"
      >
        <svg viewBox="0 0 24 24" class="w-6 h-6" fill="white">
          <path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/>
        </svg>
      </button>
    </div>

    <!-- Bottom actions -->
    <div class="mt-auto pt-4 flex flex-col items-center gap-3 animate-fadeInUp delay-3" style="opacity:0">
      <button
        class="w-full h-[46px] rounded-[14px] bg-ios-gray6 border border-ios-gray5 text-[14px] text-ios-gray font-medium flex items-center justify-center gap-2 ios-press"
        @click="loginAsGuest"
      >
        <svg viewBox="0 0 24 24" class="w-4 h-4" fill="currentColor">
          <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
        </svg>
        Tiếp tục với tư cách Khách
      </button>
      <div class="flex gap-1">
        <span class="text-[13px] text-ios-gray">Chưa có tài khoản?</span>
        <NuxtLink to="/auth/register" class="text-[13px] text-calor-green font-semibold">Đăng ký</NuxtLink>
      </div>
    </div>
  </div>
</template>
