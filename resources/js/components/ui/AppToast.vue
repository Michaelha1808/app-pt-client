<script setup lang="ts">
const { toasts } = useToast()
</script>

<template>
  <Teleport to="body">
    <div class="fixed top-4 inset-x-4 z-[200] flex flex-col items-stretch gap-2 pointer-events-none">
      <TransitionGroup name="toast">
        <div
          v-for="toast in toasts"
          :key="toast.id"
          class="flex items-center gap-3 px-4 py-3.5 rounded-[16px] shadow-lg pointer-events-auto"
          :class="{
            'bg-[#1D9655]': toast.type === 'success',
            'bg-[#D93025]': toast.type === 'error',
            'bg-[#0A84FF]': toast.type === 'info',
          }"
        >
          <!-- Success icon -->
          <svg v-if="toast.type === 'success'" viewBox="0 0 24 24" class="w-5 h-5 flex-shrink-0" fill="white">
            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
          </svg>
          <!-- Error icon -->
          <svg v-else-if="toast.type === 'error'" viewBox="0 0 24 24" class="w-5 h-5 flex-shrink-0" fill="white">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
          </svg>
          <!-- Info icon -->
          <svg v-else viewBox="0 0 24 24" class="w-5 h-5 flex-shrink-0" fill="white">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
          </svg>
          <p class="text-white text-[14px] font-medium flex-1 leading-snug">{{ toast.message }}</p>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<style scoped>
.toast-enter-active {
  transition: all 0.28s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.toast-leave-active {
  transition: all 0.2s ease-in;
}
.toast-enter-from {
  opacity: 0;
  transform: translateY(-20px) scale(0.96);
}
.toast-leave-to {
  opacity: 0;
  transform: translateY(-8px) scale(0.96);
}
</style>
