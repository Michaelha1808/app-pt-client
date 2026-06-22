<script setup lang="ts">
const { permission, requestPermission } = useNotifications()
const { isLoggedIn } = useAuth()

const dismissed = ref(localStorage.getItem('notif_banner_dismissed') === '1')
const requesting = ref(false)

const show = computed(() =>
  isLoggedIn.value &&
  !dismissed.value &&
  typeof Notification !== 'undefined' &&
  permission.value === 'default',
)

function dismiss() {
  dismissed.value = true
  localStorage.setItem('notif_banner_dismissed', '1')
}

async function handleAllow() {
  requesting.value = true
  const granted = await requestPermission()
  requesting.value = false
  if (granted) dismiss()
}
</script>

<template>
  <Transition
    enter-active-class="transition-all duration-300 ease-out"
    enter-from-class="opacity-0 -translate-y-2"
    enter-to-class="opacity-100 translate-y-0"
    leave-active-class="transition-all duration-200 ease-in"
    leave-from-class="opacity-100 translate-y-0"
    leave-to-class="opacity-0 -translate-y-2"
  >
    <div v-if="show" class="mx-4 mb-3 bg-white rounded-[16px] shadow-sm overflow-hidden">
      <div class="flex items-start gap-3 p-4">
        <span class="text-2xl mt-0.5 flex-shrink-0">🔔</span>
        <div class="flex-1 min-w-0">
          <p class="text-[14px] font-semibold text-black">Bật nhắc nhở calo?</p>
          <p class="text-[13px] text-ios-gray leading-snug mt-0.5">Nhận thông báo đầu / giữa / cuối ngày để đạt mục tiêu.</p>
          <div class="flex gap-2 mt-3">
            <button
              class="flex-1 py-2 bg-ios-blue text-white text-[14px] font-semibold rounded-[10px] ios-press"
              :disabled="requesting"
              @click="handleAllow"
            >
              {{ requesting ? 'Đang bật...' : 'Bật ngay' }}
            </button>
            <button
              class="px-4 py-2 bg-ios-gray5 text-ios-gray text-[14px] font-medium rounded-[10px] ios-press"
              @click="dismiss"
            >
              Bỏ qua
            </button>
          </div>
        </div>
      </div>
    </div>
  </Transition>
</template>
