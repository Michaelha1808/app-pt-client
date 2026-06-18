<script setup lang="ts">
const { needRefresh, updateServiceWorker } = useRegisterSW()

async function close() {
  needRefresh.value = false
}
</script>

<template>
  <!-- Update available toast -->
  <Transition name="toast">
    <div
      v-if="needRefresh"
      class="fixed top-4 left-4 right-4 z-[999] max-w-[390px] mx-auto"
    >
      <div class="bg-black/90 backdrop-blur-xl rounded-[16px] px-4 py-3.5 flex items-center gap-3 shadow-2xl">
        <div class="w-9 h-9 rounded-[10px] bg-ios-blue flex items-center justify-center flex-shrink-0">
          <svg viewBox="0 0 24 24" class="w-5 h-5" fill="white">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14v-4H7l5-8v4h4l-5 8z"/>
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-white text-[14px] font-semibold">Cập nhật mới</p>
          <p class="text-white/60 text-[12px]">Nhấn để tải phiên bản mới nhất</p>
        </div>
        <div class="flex gap-2">
          <button
            class="text-white/50 text-[13px] px-2 py-1 rounded-[8px]"
            @click="close"
          >Bỏ qua</button>
          <button
            class="bg-ios-blue text-white text-[13px] font-semibold px-3 py-1.5 rounded-[8px]"
            @click="updateServiceWorker()"
          >Cập nhật</button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.toast-enter-active, .toast-leave-active {
  transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.toast-enter-from, .toast-leave-to {
  opacity: 0;
  transform: translateY(-12px) scale(0.96);
}
</style>
