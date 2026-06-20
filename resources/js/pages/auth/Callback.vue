<script setup lang="ts">
const route = useRoute()
const { handleOAuthCallback } = useAuth()
const error = ref('')

onMounted(async () => {
  const oauthToken = route.query.token as string
  if (!oauthToken) {
    await navigateTo('/auth/login')
    return
  }
  try {
    await handleOAuthCallback(oauthToken)
  } catch {
    error.value = 'Đăng nhập Google thất bại. Vui lòng thử lại.'
  }
})
</script>

<template>
  <div class="flex flex-col items-center justify-center min-h-full gap-4 px-6">
    <template v-if="!error">
      <svg class="w-10 h-10 animate-spin text-calor-green" viewBox="0 0 24 24" fill="none">
        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.2"/>
        <path d="M12 2a10 10 0 0110 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
      </svg>
      <p class="text-[15px] text-ios-gray">Đang xác thực...</p>
    </template>
    <template v-else>
      <div class="w-16 h-16 rounded-full bg-red-100 flex items-center justify-center">
        <svg viewBox="0 0 24 24" class="w-8 h-8 text-red-500" fill="currentColor">
          <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
        </svg>
      </div>
      <p class="text-[15px] text-center text-black font-medium">{{ error }}</p>
      <NuxtLink
        to="/auth/login"
        class="mt-2 text-[15px] text-calor-green font-semibold"
      >
        Quay lại đăng nhập
      </NuxtLink>
    </template>
  </div>
</template>
