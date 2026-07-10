<script setup lang="ts">
const router = useRouter()
const { user, verifyEmail, resendVerificationCode, extractError } = useAuth()
const toast = useToast()

const code = ref('')
const loading = ref(false)
const fieldError = ref('')
const cooldown = ref(0)
let cooldownTimer: ReturnType<typeof setInterval> | null = null

function startCooldown(seconds = 60) {
  cooldown.value = seconds
  if (cooldownTimer) clearInterval(cooldownTimer)
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

onMounted(() => {
  if (user.value?.email_verified) router.replace('/profile')
})

async function submit() {
  fieldError.value = ''
  if (!/^\d{6}$/.test(code.value)) {
    fieldError.value = 'Mã xác thực gồm 6 chữ số'
    return
  }
  loading.value = true
  try {
    await verifyEmail(code.value)
    toast.success('Đã xác thực email thành công')
    router.back()
  } catch (err) {
    fieldError.value = extractError(err)
  } finally {
    loading.value = false
  }
}

async function resend() {
  if (cooldown.value > 0) return
  loading.value = true
  fieldError.value = ''
  try {
    await resendVerificationCode()
    toast.success('Đã gửi lại mã xác thực')
    startCooldown()
  } catch (err) {
    fieldError.value = extractError(err)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="pb-10">
    <!-- Header -->
    <div class="flex items-center gap-3 px-4 pt-2 pb-4">
      <button class="ios-press p-1 -ml-1" @click="router.back()">
        <svg viewBox="0 0 24 24" class="w-6 h-6" fill="#007AFF">
          <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
        </svg>
      </button>
      <h1 class="text-[17px] font-semibold text-black">Xác thực email</h1>
    </div>

    <div class="px-6">
      <div class="w-16 h-16 rounded-[14px] bg-ios-blue/10 flex items-center justify-center mb-5">
        <svg viewBox="0 0 24 24" class="w-9 h-9" fill="#007AFF">
          <path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
        </svg>
      </div>

      <h2 class="text-[22px] font-bold text-black">Nhập mã xác thực</h2>
      <p class="text-[14px] text-ios-gray mt-2 leading-relaxed">
        Chúng tôi đã gửi mã gồm 6 chữ số đến <span class="font-medium text-black">{{ user?.email }}</span>. Mã có hiệu lực trong 15 phút.
      </p>

      <!-- Code field -->
      <div class="mt-6">
        <div
          class="bg-ios-gray6 rounded-[14px] px-4 py-3.5 border transition-colors"
          :class="fieldError ? 'border-red-400' : 'border-transparent'"
        >
          <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-1">Mã xác thực</label>
          <input
            v-model="code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6"
            placeholder="000000"
            class="w-full bg-transparent text-[24px] tracking-[8px] text-black placeholder-ios-gray3 outline-none"
            @input="fieldError = ''; code = code.replace(/\D/g, '')"
            @keyup.enter="submit"
          />
        </div>
        <p v-if="fieldError" class="text-[12px] text-red-500 mt-1 px-1">{{ fieldError }}</p>
      </div>

      <button
        class="mt-4 w-full h-[52px] bg-ios-blue rounded-[14px] text-white font-semibold text-[17px] flex items-center justify-center ios-press transition-opacity"
        :disabled="loading"
        :class="loading ? 'opacity-70' : ''"
        @click="submit"
      >
        <svg v-if="loading" class="w-5 h-5 animate-spin" viewBox="0 0 24 24" fill="none">
          <circle cx="12" cy="12" r="10" stroke="white" stroke-width="3" opacity="0.3"/>
          <path d="M12 2a10 10 0 0110 10" stroke="white" stroke-width="3" stroke-linecap="round"/>
        </svg>
        <span v-else>Xác nhận</span>
      </button>

      <div class="mt-4 flex justify-center">
        <button
          class="text-[14px] font-medium transition-colors"
          :class="cooldown > 0 ? 'text-ios-gray cursor-not-allowed' : 'text-ios-blue ios-press'"
          :disabled="cooldown > 0 || loading"
          @click="resend"
        >
          <span v-if="cooldown > 0">Gửi lại mã sau {{ cooldown }}s</span>
          <span v-else>Gửi lại mã</span>
        </button>
      </div>
    </div>
  </div>
</template>
