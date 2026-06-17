<script setup lang="ts">
definePageMeta({ layout: 'auth' })

const route = useRoute()
const { resetPassword, extractError } = useAuth()

const resetToken = computed(() => route.query.token as string | undefined)

const newPassword = ref('')
const confirmPassword = ref('')
const showPassword = ref(false)
const showConfirm = ref(false)
const loading = ref(false)
const success = ref(false)
const formError = ref('')
const errors = reactive({ newPassword: '', confirmPassword: '' })
const redirectCountdown = ref(3)

let redirectTimer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  if (!resetToken.value) navigateTo('/auth/forgot-password')
})

onUnmounted(() => {
  if (redirectTimer) clearInterval(redirectTimer)
})

function startRedirect() {
  redirectTimer = setInterval(() => {
    redirectCountdown.value--
    if (redirectCountdown.value <= 0) {
      clearInterval(redirectTimer!)
      navigateTo('/auth/login')
    }
  }, 1000)
}

function validate(): boolean {
  errors.newPassword = ''
  errors.confirmPassword = ''
  let ok = true
  if (newPassword.value.length < 8) {
    errors.newPassword = 'Mật khẩu tối thiểu 8 ký tự'
    ok = false
  } else if (!/[A-Z]/.test(newPassword.value) || !/[0-9]/.test(newPassword.value)) {
    errors.newPassword = 'Cần có ít nhất 1 chữ hoa và 1 số'
    ok = false
  }
  if (!errors.newPassword && newPassword.value !== confirmPassword.value) {
    errors.confirmPassword = 'Mật khẩu xác nhận không khớp'
    ok = false
  }
  return ok
}

async function handleSubmit() {
  if (!validate()) return
  loading.value = true
  formError.value = ''
  try {
    await resetPassword(resetToken.value!, newPassword.value)
    success.value = true
    startRedirect()
  } catch (err) {
    formError.value = extractError(err)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex flex-col min-h-full px-6 pb-10">
    <!-- Back -->
    <button
      class="w-9 h-9 rounded-full bg-ios-gray6 flex items-center justify-center mt-2 ios-press"
      @click="navigateTo('/auth/login')"
    >
      <svg viewBox="0 0 24 24" class="w-5 h-5" fill="#007AFF">
        <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
      </svg>
    </button>

    <!-- Success state -->
    <div v-if="success" class="flex-1 flex flex-col items-center justify-center gap-4 animate-scaleIn">
      <div class="w-24 h-24 rounded-full bg-ios-green/10 flex items-center justify-center">
        <svg viewBox="0 0 24 24" class="w-12 h-12" fill="#34C759">
          <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
        </svg>
      </div>
      <h2 class="text-[22px] font-bold text-black text-center">Mật khẩu đã được cập nhật!</h2>
      <p class="text-[15px] text-ios-gray text-center leading-relaxed">
        Bạn sẽ được chuyển về trang đăng nhập sau <span class="font-semibold text-black">{{ redirectCountdown }}s</span>
      </p>
      <button
        class="mt-4 w-full h-[52px] bg-calor-green rounded-[14px] text-white font-semibold text-[17px] ios-press"
        @click="navigateTo('/auth/login')"
      >
        Đăng nhập ngay
      </button>
    </div>

    <!-- Form state -->
    <div v-else class="flex flex-col">
      <div class="mt-8 mb-8 animate-fadeInUp" style="opacity:0">
        <div class="w-16 h-16 rounded-[14px] bg-calor-green/10 flex items-center justify-center mb-5">
          <svg viewBox="0 0 24 24" class="w-9 h-9" fill="#18A874">
            <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 4l5 2.18V11c0 3.5-2.33 6.79-5 7.93-2.67-1.14-5-4.43-5-7.93V7.18L12 5z"/>
          </svg>
        </div>
        <h2 class="text-[26px] font-bold text-black">Đặt lại mật khẩu</h2>
        <p class="text-[14px] text-ios-gray mt-2 leading-relaxed">
          Tạo mật khẩu mới cho tài khoản của bạn. Mật khẩu cần ít nhất 8 ký tự, có chữ hoa và số.
        </p>
      </div>

      <div class="flex flex-col gap-3 animate-fadeInUp delay-1" style="opacity:0">
        <!-- New password -->
        <div>
          <div
            class="bg-ios-gray6 rounded-[14px] px-4 py-3.5 border transition-colors"
            :class="errors.newPassword ? 'border-red-400' : 'border-transparent'"
          >
            <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-1">Mật khẩu mới</label>
            <div class="flex items-center">
              <input
                v-model="newPassword"
                :type="showPassword ? 'text' : 'password'"
                placeholder="Tối thiểu 8 ký tự"
                class="flex-1 bg-transparent text-[16px] text-black placeholder-ios-gray3 outline-none"
                @input="errors.newPassword = ''"
              />
              <button class="text-ios-gray ml-2 ios-press" @click="showPassword = !showPassword">
                <svg viewBox="0 0 24 24" class="w-5 h-5" fill="currentColor">
                  <path v-if="showPassword" d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
                  <path v-else d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46A11.804 11.804 0 001 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/>
                </svg>
              </button>
            </div>
          </div>
          <p v-if="errors.newPassword" class="text-[12px] text-red-500 mt-1 px-1">{{ errors.newPassword }}</p>
        </div>

        <!-- Confirm password -->
        <div>
          <div
            class="bg-ios-gray6 rounded-[14px] px-4 py-3.5 border transition-colors"
            :class="errors.confirmPassword ? 'border-red-400' : 'border-transparent'"
          >
            <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-1">Xác nhận mật khẩu mới</label>
            <div class="flex items-center">
              <input
                v-model="confirmPassword"
                :type="showConfirm ? 'text' : 'password'"
                placeholder="Nhập lại mật khẩu mới"
                class="flex-1 bg-transparent text-[16px] text-black placeholder-ios-gray3 outline-none"
                @input="errors.confirmPassword = ''"
              />
              <button class="text-ios-gray ml-2 ios-press" @click="showConfirm = !showConfirm">
                <svg viewBox="0 0 24 24" class="w-5 h-5" fill="currentColor">
                  <path v-if="showConfirm" d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
                  <path v-else d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46A11.804 11.804 0 001 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/>
                </svg>
              </button>
            </div>
          </div>
          <p v-if="errors.confirmPassword" class="text-[12px] text-red-500 mt-1 px-1">{{ errors.confirmPassword }}</p>
        </div>

        <!-- API error (e.g. token expired) -->
        <div v-if="formError" class="bg-red-50 border border-red-200 rounded-[12px] px-4 py-3 flex items-start gap-2.5">
          <svg viewBox="0 0 24 24" class="w-4 h-4 text-red-500 flex-shrink-0 mt-0.5" fill="currentColor">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
          </svg>
          <div>
            <p class="text-[13px] text-red-600 leading-snug">{{ formError }}</p>
            <NuxtLink
              to="/auth/forgot-password"
              class="text-[12px] text-red-500 underline mt-1 inline-block"
            >
              Yêu cầu link mới
            </NuxtLink>
          </div>
        </div>

        <button
          class="mt-1 w-full h-[52px] bg-calor-green rounded-[14px] text-white font-semibold text-[17px] flex items-center justify-center ios-press transition-opacity"
          :disabled="loading"
          :class="loading ? 'opacity-70' : ''"
          @click="handleSubmit"
        >
          <svg v-if="loading" class="w-5 h-5 animate-spin" viewBox="0 0 24 24" fill="none">
            <circle cx="12" cy="12" r="10" stroke="white" stroke-width="3" opacity="0.3"/>
            <path d="M12 2a10 10 0 0110 10" stroke="white" stroke-width="3" stroke-linecap="round"/>
          </svg>
          <span v-else>Đặt lại mật khẩu</span>
        </button>
      </div>
    </div>
  </div>
</template>
