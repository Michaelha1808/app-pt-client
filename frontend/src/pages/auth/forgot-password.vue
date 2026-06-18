<script setup lang="ts">
definePageMeta({ layout: 'auth', middleware: 'guest' })

const { forgotPassword, extractError } = useAuth()

const email = ref('')
const sent = ref(false)
const loading = ref(false)
const fieldError = ref('')
const formError = ref('')
const cooldown = ref(0)

const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

let cooldownTimer: ReturnType<typeof setInterval> | null = null

function startCooldown() {
  cooldown.value = 60
  cooldownTimer = setInterval(() => {
    cooldown.value--
    if (cooldown.value <= 0 && cooldownTimer) {
      clearInterval(cooldownTimer)
      cooldownTimer = null
    }
  }, 1000)
}

onUnmounted(() => {
  if (cooldownTimer) clearInterval(cooldownTimer)
})

async function handleSubmit() {
  fieldError.value = ''
  formError.value = ''
  if (!email.value) { fieldError.value = 'Vui lòng nhập email'; return }
  if (!emailRe.test(email.value)) { fieldError.value = 'Email không hợp lệ'; return }

  loading.value = true
  try {
    await forgotPassword(email.value)
    sent.value = true
    startCooldown()
  } catch (err) {
    formError.value = extractError(err)
  } finally {
    loading.value = false
  }
}

async function handleResend() {
  if (cooldown.value > 0) return
  loading.value = true
  formError.value = ''
  try {
    await forgotPassword(email.value)
    startCooldown()
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
    <div v-if="sent" class="flex-1 flex flex-col items-center justify-center gap-4 animate-scaleIn">
      <div class="w-24 h-24 rounded-full bg-ios-green/10 flex items-center justify-center">
        <svg viewBox="0 0 24 24" class="w-12 h-12" fill="#34C759">
          <path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
        </svg>
      </div>
      <h2 class="text-[22px] font-bold text-black text-center">Email đã được gửi!</h2>
      <p class="text-[15px] text-ios-gray text-center leading-relaxed px-2">
        Kiểm tra hộp thư <span class="font-medium text-black">{{ email }}</span> và làm theo hướng dẫn để đặt lại mật khẩu.
      </p>

      <!-- API error after resend -->
      <div v-if="formError" class="w-full bg-red-50 border border-red-200 rounded-[12px] px-4 py-3 flex items-start gap-2.5">
        <svg viewBox="0 0 24 24" class="w-4 h-4 text-red-500 flex-shrink-0 mt-0.5" fill="currentColor">
          <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
        </svg>
        <p class="text-[13px] text-red-600 leading-snug">{{ formError }}</p>
      </div>

      <!-- Resend with cooldown -->
      <button
        class="text-[14px] font-medium transition-colors"
        :class="cooldown > 0 ? 'text-ios-gray cursor-not-allowed' : 'text-ios-blue ios-press'"
        :disabled="cooldown > 0 || loading"
        @click="handleResend"
      >
        <span v-if="cooldown > 0">Gửi lại sau {{ cooldown }}s</span>
        <span v-else-if="loading">Đang gửi...</span>
        <span v-else>Gửi lại email</span>
      </button>

      <button
        class="mt-2 w-full h-[52px] bg-ios-blue rounded-[14px] text-white font-semibold text-[17px] ios-press"
        @click="navigateTo('/auth/login')"
      >
        Trở về đăng nhập
      </button>
    </div>

    <!-- Form state -->
    <div v-else class="flex flex-col">
      <div class="mt-8 mb-8 animate-fadeInUp" style="opacity:0">
        <div class="w-16 h-16 rounded-[14px] bg-ios-blue/10 flex items-center justify-center mb-5">
          <svg viewBox="0 0 24 24" class="w-9 h-9" fill="#007AFF">
            <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
          </svg>
        </div>
        <h2 class="text-[26px] font-bold text-black">Quên mật khẩu?</h2>
        <p class="text-[14px] text-ios-gray mt-2 leading-relaxed">
          Nhập email đăng ký của bạn và chúng tôi sẽ gửi hướng dẫn đặt lại mật khẩu.
        </p>
      </div>

      <!-- Email field -->
      <div class="animate-fadeInUp delay-1" style="opacity:0">
        <div
          class="bg-ios-gray6 rounded-[14px] px-4 py-3.5 border transition-colors"
          :class="fieldError ? 'border-red-400' : 'border-transparent'"
        >
          <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-1">Email</label>
          <input
            v-model="email" type="email" placeholder="ten@email.com"
            class="w-full bg-transparent text-[16px] text-black placeholder-ios-gray3 outline-none"
            @input="fieldError = ''"
            @keyup.enter="handleSubmit"
          />
        </div>
        <p v-if="fieldError" class="text-[12px] text-red-500 mt-1 px-1">{{ fieldError }}</p>
      </div>

      <!-- API error -->
      <div v-if="formError" class="mt-3 bg-red-50 border border-red-200 rounded-[12px] px-4 py-3 flex items-start gap-2.5">
        <svg viewBox="0 0 24 24" class="w-4 h-4 text-red-500 flex-shrink-0 mt-0.5" fill="currentColor">
          <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
        </svg>
        <p class="text-[13px] text-red-600 leading-snug">{{ formError }}</p>
      </div>

      <button
        class="mt-4 w-full h-[52px] bg-ios-blue rounded-[14px] text-white font-semibold text-[17px] flex items-center justify-center ios-press animate-fadeInUp delay-2 transition-opacity"
        style="opacity:0"
        :disabled="loading"
        :class="loading ? 'opacity-70' : ''"
        @click="handleSubmit"
      >
        <svg v-if="loading" class="w-5 h-5 animate-spin" viewBox="0 0 24 24" fill="none">
          <circle cx="12" cy="12" r="10" stroke="white" stroke-width="3" opacity="0.3"/>
          <path d="M12 2a10 10 0 0110 10" stroke="white" stroke-width="3" stroke-linecap="round"/>
        </svg>
        <span v-else>Gửi email đặt lại</span>
      </button>
    </div>
  </div>
</template>
