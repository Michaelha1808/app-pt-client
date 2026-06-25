<script setup lang="ts">
/**
 * Install prompt.
 * - Android/desktop: uses the native beforeinstallprompt event (one-tap install).
 * - iOS Safari: no install API, so we show instructions to use the Share sheet.
 * Hidden when the app is already installed (running in standalone display mode).
 */
const show = ref(false)
const isIOS = ref(false)
let deferredPrompt: any = null

onMounted(() => {
  if (typeof window === 'undefined') return

  // Already installed? Don't show anything.
  const isStandalone =
    window.matchMedia('(display-mode: standalone)').matches ||
    (navigator as any).standalone === true
  if (isStandalone) return

  const ua = navigator.userAgent
  isIOS.value =
    /iphone|ipad|ipod/i.test(ua) ||
    // iPadOS 13+ reports as Mac, detect via touch support
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)

  if (isIOS.value) {
    // iOS can't trigger an install programmatically — show the how-to after a short delay.
    setTimeout(() => { show.value = true }, 1500)
    return
  }

  window.addEventListener('beforeinstallprompt', (e: Event) => {
    e.preventDefault()
    deferredPrompt = e
    show.value = true
  })
})

async function install() {
  if (!deferredPrompt) return
  deferredPrompt.prompt()
  await deferredPrompt.userChoice
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
        <img
          :src="'/logo/caloreye_icon_192.png'"
          alt="CaloEye"
          class="w-12 h-12 rounded-[12px] object-contain flex-shrink-0"
        />

        <!-- iOS: instructions to add via the Share sheet -->
        <template v-if="isIOS">
          <div class="flex-1 min-w-0">
            <p class="text-[15px] font-semibold text-black">Cài đặt CaloEye</p>
            <p class="text-[12px] text-ios-gray leading-tight mt-0.5">
              Nhấn
              <svg viewBox="0 0 24 24" class="inline-block w-4 h-4 -mt-0.5 align-middle text-ios-blue" fill="currentColor">
                <path d="M12 2 8 6h3v8h2V6h3l-4-4zM5 10a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-3v2h3v8H5v-8h3v-2H5z"/>
              </svg>
              rồi chọn <span class="font-medium text-black">"Thêm vào MH chính"</span>
            </p>
          </div>
          <button
            class="text-ios-gray text-[12px] text-center ios-press self-start"
            @click="dismiss"
          >Đóng</button>
        </template>

        <!-- Android / desktop: one-tap install -->
        <template v-else>
          <div class="flex-1 min-w-0">
            <p class="text-[15px] font-semibold text-black">Cài đặt CaloEye</p>
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
        </template>
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
