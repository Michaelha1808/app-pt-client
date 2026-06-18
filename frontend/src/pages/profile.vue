<script setup lang="ts">
definePageMeta({ layout: 'app', middleware: 'auth-strict' })

const { user: authUser, logout } = useAuth()

const calorieGoal = ref(2000)
const morningNotif = ref(true)
const noonNotif = ref(true)
const eveningNotif = ref(true)
const pushNotif = ref(true)
const darkMode = ref(false)
const logoutLoading = ref(false)

// Fallback mock body stats until profile API is implemented
const bodyStats = {
  age: 26,
  height: 170,
  weight: 70,
  gender: 'Nam',
}

const displayName = computed(() => authUser.value?.name ?? 'Người dùng')
const displayEmail = computed(() => authUser.value?.email ?? '')
const displayAvatar = computed(() => displayName.value.charAt(0).toUpperCase())

const bmi = computed(() => {
  const h = bodyStats.height / 100
  return (bodyStats.weight / (h * h)).toFixed(1)
})

const bmr = computed(() => {
  return Math.round(10 * bodyStats.weight + 6.25 * bodyStats.height - 5 * bodyStats.age + 5)
})

const bmiLabel = computed(() => {
  const b = parseFloat(bmi.value)
  if (b < 18.5) return { text: 'Gầy', color: '#32ADE6' }
  if (b < 25) return { text: 'Bình thường', color: '#34C759' }
  if (b < 30) return { text: 'Thừa cân', color: '#FF9500' }
  return { text: 'Béo phì', color: '#FF3B30' }
})

const connectedDevices = ref([
  { id: 'strava', name: 'Strava', icon: '🏃', connected: true, color: '#FC4C02' },
  { id: 'health', name: 'Apple Health', icon: '❤️', connected: false, color: '#FF2D55' },
  { id: 'garmin', name: 'Garmin Connect', icon: '⌚', connected: false, color: '#007CC2' },
])

function toggleDevice(id: string) {
  const d = connectedDevices.value.find(d => d.id === id)
  if (d) d.connected = !d.connected
}

async function handleLogout() {
  logoutLoading.value = true
  try {
    await logout()
  } finally {
    logoutLoading.value = false
  }
}
</script>

<template>
  <div class="pb-8">
    <!-- Header -->
    <div class="px-5 pt-2 pb-4 animate-fadeInUp" style="opacity:0">
      <h1 class="text-[28px] font-bold text-black">Hồ sơ</h1>
    </div>

    <!-- Profile card -->
    <div class="mx-5 mb-4 animate-fadeInUp delay-1" style="opacity:0">
      <div class="bg-gradient-to-br from-ios-blue to-ios-purple rounded-[20px] p-5 text-white">
        <div class="flex items-center gap-4">
          <!-- Avatar -->
          <div class="relative">
            <div class="w-16 h-16 rounded-full bg-white/25 flex items-center justify-center">
              <span class="text-white font-bold text-[24px]">{{ displayAvatar }}</span>
            </div>
            <button class="absolute -bottom-1 -right-1 w-6 h-6 bg-white rounded-full flex items-center justify-center">
              <svg viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="#007AFF">
                <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>
              </svg>
            </button>
          </div>

          <div class="flex-1">
            <h2 class="text-[18px] font-bold">{{ displayName }}</h2>
            <p class="text-white/70 text-[13px]">{{ displayEmail }}</p>
            <div class="flex gap-3 mt-2">
              <span class="text-[12px] text-white/80">{{ bodyStats.age }} tuổi</span>
              <span class="text-white/40">·</span>
              <span class="text-[12px] text-white/80">{{ bodyStats.height }}cm</span>
              <span class="text-white/40">·</span>
              <span class="text-[12px] text-white/80">{{ bodyStats.weight }}kg</span>
            </div>
          </div>
        </div>

        <!-- Stats strip -->
        <div class="flex gap-4 mt-4 pt-4 border-t border-white/20">
          <div class="flex-1 text-center">
            <p class="text-[20px] font-bold">{{ bmi }}</p>
            <p class="text-[11px] text-white/70">BMI</p>
            <p class="text-[10px] font-semibold mt-0.5" :style="`color: ${bmiLabel.color}`">{{ bmiLabel.text }}</p>
          </div>
          <div class="w-px bg-white/20"/>
          <div class="flex-1 text-center">
            <p class="text-[20px] font-bold">{{ bmr.toLocaleString('vi') }}</p>
            <p class="text-[11px] text-white/70">BMR (kcal)</p>
            <p class="text-[10px] text-white/60 mt-0.5">Calo cơ bản</p>
          </div>
          <div class="w-px bg-white/20"/>
          <div class="flex-1 text-center">
            <p class="text-[20px] font-bold">32</p>
            <p class="text-[11px] text-white/70">Ngày liên tiếp</p>
            <p class="text-[10px] text-white/60 mt-0.5">🔥 Streak</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Mục tiêu -->
    <div class="px-5 mb-2 animate-fadeInUp delay-2" style="opacity:0">
      <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-2 px-1">Mục tiêu</p>
      <div class="bg-white rounded-[16px] overflow-hidden shadow-sm">
        <div class="flex items-center gap-3 px-4 py-3.5">
          <div class="w-8 h-8 rounded-[8px] bg-ios-orange/15 flex items-center justify-center">
            <span class="text-lg">🎯</span>
          </div>
          <div class="flex-1">
            <p class="text-[15px] text-black">Calo mục tiêu / ngày</p>
          </div>
          <div class="flex items-center gap-1.5">
            <button
              class="w-6 h-6 rounded-full bg-ios-gray5 flex items-center justify-center ios-press"
              @click="calorieGoal = Math.max(1200, calorieGoal - 100)"
            >
              <svg viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="#8E8E93"><path d="M19 13H5v-2h14v2z"/></svg>
            </button>
            <span class="text-[15px] font-semibold text-ios-blue w-14 text-center">{{ calorieGoal.toLocaleString('vi') }}</span>
            <button
              class="w-6 h-6 rounded-full bg-ios-gray5 flex items-center justify-center ios-press"
              @click="calorieGoal = Math.min(4000, calorieGoal + 100)"
            >
              <svg viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="#8E8E93"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Thông báo -->
    <div class="px-5 mb-2 animate-fadeInUp delay-3" style="opacity:0">
      <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-2 px-1">Thông báo</p>
      <div class="bg-white rounded-[16px] overflow-hidden shadow-sm">
        <template v-for="(item, idx) in [
          { key: 'pushNotif', label: 'Bật thông báo', icon: '🔔', model: pushNotif },
          { key: 'morningNotif', label: 'Lời chào buổi sáng', icon: '🌅', time: '07:00', model: morningNotif },
          { key: 'noonNotif', label: 'Nhắc nhở giữa ngày', icon: '☀️', time: '12:00', model: noonNotif },
          { key: 'eveningNotif', label: 'Tổng kết cuối ngày', icon: '🌙', time: '21:00', model: eveningNotif },
        ]" :key="item.key">
          <div class="flex items-center gap-3 px-4 py-3">
            <span class="text-lg w-6 text-center">{{ item.icon }}</span>
            <span class="flex-1 text-[15px] text-black">{{ item.label }}</span>
            <span v-if="item.time" class="text-[13px] text-ios-gray mr-2">{{ item.time }}</span>
            <!-- iOS Toggle -->
            <button
              class="w-12 h-7 rounded-full transition-colors duration-200 relative flex-shrink-0"
              :class="item.model ? 'bg-ios-green' : 'bg-ios-gray4'"
              @click="item.key === 'pushNotif' ? pushNotif = !pushNotif
                    : item.key === 'morningNotif' ? morningNotif = !morningNotif
                    : item.key === 'noonNotif' ? noonNotif = !noonNotif
                    : eveningNotif = !eveningNotif"
            >
              <div
                class="absolute top-[3px] w-[22px] h-[22px] bg-white rounded-full shadow-md transition-all duration-200"
                :class="item.model ? 'left-[22px]' : 'left-[3px]'"
              />
            </button>
          </div>
          <div v-if="idx < 3" class="ios-separator mx-4"/>
        </template>
      </div>
    </div>

    <!-- Kết nối thiết bị -->
    <div class="px-5 mb-2 animate-fadeInUp delay-4" style="opacity:0">
      <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-2 px-1">Kết nối thiết bị</p>
      <div class="bg-white rounded-[16px] overflow-hidden shadow-sm">
        <div v-for="(device, idx) in connectedDevices" :key="device.id">
          <div class="flex items-center gap-3 px-4 py-3.5">
            <div
              class="w-9 h-9 rounded-[10px] flex items-center justify-center text-lg"
              :style="`background: ${device.color}18`"
            >{{ device.icon }}</div>
            <span class="flex-1 text-[15px] text-black">{{ device.name }}</span>
            <button
              class="px-3 py-1 rounded-full text-[12px] font-semibold ios-press"
              :class="device.connected
                ? 'bg-ios-red/10 text-ios-red'
                : 'bg-ios-blue/10 text-ios-blue'"
              @click="toggleDevice(device.id)"
            >{{ device.connected ? 'Ngắt kết nối' : 'Kết nối' }}</button>
          </div>
          <div v-if="idx < connectedDevices.length - 1" class="ios-separator mx-4"/>
        </div>
      </div>
    </div>

    <!-- Tài khoản -->
    <div class="px-5 mb-2 animate-fadeInUp delay-5" style="opacity:0">
      <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-2 px-1">Tài khoản</p>
      <div class="bg-white rounded-[16px] overflow-hidden shadow-sm">
        <button class="w-full flex items-center gap-3 px-4 py-3.5 ios-press">
          <div class="w-8 h-8 rounded-[8px] bg-ios-blue/10 flex items-center justify-center">
            <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#007AFF"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
          </div>
          <span class="flex-1 text-left text-[15px] text-black">Chỉnh sửa hồ sơ</span>
          <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#C7C7CC"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/></svg>
        </button>
        <div class="ios-separator mx-4"/>
        <button class="w-full flex items-center gap-3 px-4 py-3.5 ios-press">
          <div class="w-8 h-8 rounded-[8px] bg-ios-purple/10 flex items-center justify-center">
            <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#AF52DE"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
          </div>
          <span class="flex-1 text-left text-[15px] text-black">Đổi mật khẩu</span>
          <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#C7C7CC"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/></svg>
        </button>
        <div class="ios-separator mx-4"/>
        <button class="w-full flex items-center gap-3 px-4 py-3.5 ios-press">
          <div class="w-8 h-8 rounded-[8px] bg-ios-orange/10 flex items-center justify-center">
            <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#FF9500"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-1 16H6l4-4 2 2.5 3-4 4 5.5zm0-9h-2V8h-2V7h4v3z"/></svg>
          </div>
          <span class="flex-1 text-left text-[15px] text-black">Xuất báo cáo</span>
          <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#C7C7CC"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/></svg>
        </button>
      </div>
    </div>

    <!-- Logout -->
    <div class="px-5 mt-4 animate-fadeInUp delay-6" style="opacity:0">
      <button
        class="w-full h-[50px] bg-white rounded-[16px] text-ios-red font-semibold text-[16px] shadow-sm ios-press flex items-center justify-center gap-2 transition-opacity"
        :class="logoutLoading ? 'opacity-60' : ''"
        :disabled="logoutLoading"
        @click="handleLogout"
      >
        <svg v-if="logoutLoading" class="w-4 h-4 animate-spin text-ios-red" viewBox="0 0 24 24" fill="none">
          <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.3"/>
          <path d="M12 2a10 10 0 0110 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        </svg>
        <span>{{ logoutLoading ? 'Đang đăng xuất...' : 'Đăng xuất' }}</span>
      </button>
    </div>

    <p class="text-center text-[11px] text-ios-gray mt-4">NutriAI v1.0.0 · Phiên bản thử nghiệm</p>
  </div>
</template>
