<script setup lang="ts">
const router = useRouter()
const { settings, loading, permission, fetchSettings, requestPermission, updateSetting } = useNotifications()

const requesting = ref(false)

onMounted(fetchSettings)

async function handleRequestPermission() {
  requesting.value = true
  await requestPermission()
  requesting.value = false
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
      <h1 class="text-[17px] font-semibold text-black">Thông báo</h1>
    </div>

    <!-- Loading skeleton -->
    <template v-if="loading">
      <div class="mx-4 mb-4 space-y-3">
        <div class="h-4 w-32 bg-gray-200 rounded animate-pulse mx-1"/>
        <div class="bg-white rounded-[16px] h-32 animate-pulse shadow-sm"/>
        <div class="h-4 w-24 bg-gray-200 rounded animate-pulse mx-1 mt-4"/>
        <div class="bg-white rounded-[16px] h-14 animate-pulse shadow-sm"/>
      </div>
    </template>

    <template v-else-if="settings">
      <!-- Permission banner -->
      <div v-if="permission !== 'granted'" class="mx-4 mb-4">
        <div class="bg-ios-blue/10 rounded-[16px] p-4 flex items-start gap-3">
          <span class="text-2xl mt-0.5">🔔</span>
          <div class="flex-1">
            <p class="text-[14px] font-semibold text-black mb-1">Chưa bật thông báo</p>
            <p class="text-[13px] text-ios-gray leading-snug mb-3">
              {{ permission === 'denied'
                ? 'Bạn đã từ chối quyền. Vào Settings → Thông báo để bật lại.'
                : 'Bật thông báo để nhận nhắc nhở calo hàng ngày.' }}
            </p>
            <button
              v-if="permission !== 'denied'"
              class="px-4 py-2 bg-ios-blue text-white text-[14px] font-semibold rounded-[10px] ios-press"
              :disabled="requesting"
              @click="handleRequestPermission"
            >
              {{ requesting ? 'Đang bật...' : 'Bật thông báo' }}
            </button>
          </div>
        </div>
      </div>

      <!-- NHẮC NHỞ HÀNG NGÀY -->
      <div class="px-4 mb-1">
        <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-2 px-1">Nhắc nhở hàng ngày</p>
        <div class="bg-white rounded-[16px] overflow-hidden shadow-sm">

          <!-- Đầu ngày -->
          <div class="flex items-center gap-3 px-4 py-3.5">
            <div class="w-8 h-8 rounded-[8px] bg-ios-orange/15 flex items-center justify-center flex-shrink-0">
              <span class="text-base">☀️</span>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-[15px] text-black">Đầu ngày</p>
              <input
                v-if="settings.morning.enabled"
                type="time"
                :value="settings.morning.time"
                class="text-[13px] text-ios-blue bg-transparent border-none outline-none p-0 mt-0.5"
                @change="(e) => updateSetting({ morning: { ...settings!.morning, time: (e.target as HTMLInputElement).value } })"
              />
              <p v-else class="text-[13px] text-ios-gray mt-0.5">Đã tắt</p>
            </div>
            <!-- iOS toggle -->
            <button
              class="relative w-[51px] h-[31px] rounded-full transition-colors duration-200 flex-shrink-0"
              :class="settings.morning.enabled ? 'bg-ios-green' : 'bg-ios-gray3'"
              @click="updateSetting({ morning: { ...settings.morning, enabled: !settings.morning.enabled } })"
            >
              <span
                class="absolute top-[2px] w-[27px] h-[27px] bg-white rounded-full shadow transition-all duration-200"
                :class="settings.morning.enabled ? 'left-[22px]' : 'left-[2px]'"
              />
            </button>
          </div>

          <div class="ml-[68px] h-px bg-black/8"/>

          <!-- Giữa ngày -->
          <div class="flex items-center gap-3 px-4 py-3.5">
            <div class="w-8 h-8 rounded-[8px] bg-ios-yellow/15 flex items-center justify-center flex-shrink-0">
              <span class="text-base">🌤️</span>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-[15px] text-black">Giữa ngày</p>
              <p class="text-[13px] text-ios-gray mt-0.5">12:00 · Còn X kcal</p>
            </div>
            <button
              class="relative w-[51px] h-[31px] rounded-full transition-colors duration-200 flex-shrink-0"
              :class="settings.midday.enabled ? 'bg-ios-green' : 'bg-ios-gray3'"
              @click="updateSetting({ midday: { enabled: !settings.midday.enabled } })"
            >
              <span
                class="absolute top-[2px] w-[27px] h-[27px] bg-white rounded-full shadow transition-all duration-200"
                :class="settings.midday.enabled ? 'left-[22px]' : 'left-[2px]'"
              />
            </button>
          </div>

          <div class="ml-[68px] h-px bg-black/8"/>

          <!-- Cuối ngày -->
          <div class="flex items-center gap-3 px-4 py-3.5">
            <div class="w-8 h-8 rounded-[8px] bg-ios-purple/15 flex items-center justify-center flex-shrink-0">
              <span class="text-base">🌙</span>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-[15px] text-black">Cuối ngày</p>
              <input
                v-if="settings.evening.enabled"
                type="time"
                :value="settings.evening.time"
                class="text-[13px] text-ios-blue bg-transparent border-none outline-none p-0 mt-0.5"
                @change="(e) => updateSetting({ evening: { ...settings!.evening, time: (e.target as HTMLInputElement).value } })"
              />
              <p v-else class="text-[13px] text-ios-gray mt-0.5">Đã tắt</p>
            </div>
            <button
              class="relative w-[51px] h-[31px] rounded-full transition-colors duration-200 flex-shrink-0"
              :class="settings.evening.enabled ? 'bg-ios-green' : 'bg-ios-gray3'"
              @click="updateSetting({ evening: { ...settings.evening, enabled: !settings.evening.enabled } })"
            >
              <span
                class="absolute top-[2px] w-[27px] h-[27px] bg-white rounded-full shadow transition-all duration-200"
                :class="settings.evening.enabled ? 'left-[22px]' : 'left-[2px]'"
              />
            </button>
          </div>
        </div>
      </div>

      <!-- Email nhắc quay lại (sau 7 ngày) chạy ngầm — không hiện toggle cho user -->
    </template>
  </div>
</template>
