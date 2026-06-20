<script setup lang="ts">
/**
 * Shows a native-feel install prompt when the browser fires beforeinstallprompt.
 * Hidden on iOS (iOS uses the share sheet, not the install API).
 */
const show = ref(false)
let deferredPrompt: any = null

onMounted(() => {
  if (typeof window === 'undefined') return
  // iOS Safari doesn't support beforeinstallprompt – skip it
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent)
  if (isIOS) return

  window.addEventListener('beforeinstallprompt', (e: Event) => {
    e.preventDefault()
    deferredPrompt = e
    show.value = true
  })
})

async function install() {
  if (!deferredPrompt) return
  deferredPrompt.prompt()
  const { outcome } = await deferredPrompt.userChoice
  deferredPrompt = null
  show.value = false
}

function dismiss() {
  show.value = false
  deferredPrompt = null
}
</script>

<template>
  <Transition name="banner">
    <div
      v-if="show"
      class="fixed bottom-28 left-4 right-4 z-[998] max-w-[390px] mx-auto"
    >
      <div class="bg-white rounded-[18px] px-4 py-4 shadow-2xl shadow-black/15 flex items-center gap-3">
        <div class="w-12 h-12 rounded-[12px] bg-ios-blue flex items-center justify-center flex-shrink-0">
            <svg viewBox="0 0 24 24" class="w-7 h-7" fill="white">
              <path d="M11 7H6v2h5V7zm0 4H6v2h5v-2zm0 4H6v2h5v-2zM4 3H2v18h2V3zm14 14h-2v2h2v-2zm0-4h-2v2h2v-2zm0-4h-2v2h2V9zm2-6h-2v18h2V3z"/>
              <circle cx="18" cy="6" r="3" fill="#34C759"/>
            </svg>
          </div>
        <div class="flex-1 min-w-0">
          <p class="text-[15px] font-semibold text-black">Cài đặt NutriAI</p>
          <p class="text-[12px] text-ios-gray leading-tight mt-0.5">Thêm vào màn hình chính để dùng ngoại tuyến</p>
        </div>
        <div class="flex flex-col gap-1.5">
          <button
            class="bg-ios-blue text-white text-[13px] font-semibold px-4 py-1.5 rounded-full ios-press"
            @click="install"
          >Cài đặt</button>
          <button
            class="text-ios-gray text-[12px] text-center ios-press"
            @click="dismiss"
          >Không, cảm ơn</button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.banner-enter-active, .banner-leave-active {
  transition: all 0.4s cubic-bezier(0.32, 0.72, 0, 1);
}
.banner-enter-from, .banner-leave-to {
  opacity: 0;
  transform: translateY(20px);
}
</style>
