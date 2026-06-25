<script setup lang="ts">
const { user } = useAuth()
const { loading, bmi, bmr, bmiLabel, age, fetchProfile, uploadAvatar, deleteAvatar } = useProfile()
const { success, error: toastError } = useToast()
const { supported: bioSupported, enabled: bioEnabled, checkSupport, fetchStatus, register: registerPasskey, disable: disablePasskey } = usePasskey()
const bioBusy = ref(false)

const avatarPicker = ref<{ open: () => void } | null>(null)
const avatarUploading = ref(false)
const logoutLoading = ref(false)
const calorieGoal = ref(2000)

const darkMode = ref(false)

const connectedDevices = ref([
  { id: 'strava', name: 'Strava', icon: '🏃', connected: true, color: '#FC4C02' },
  { id: 'health', name: 'Apple Health', icon: '❤️', connected: false, color: '#FF2D55' },
  { id: 'garmin', name: 'Garmin Connect', icon: '⌚', connected: false, color: '#007CC2' },
])

const displayName = computed(() => user.value?.name ?? 'Người dùng')
const displayEmail = computed(() => user.value?.email ?? '')
const displayAvatar = computed(() => displayName.value.charAt(0).toUpperCase())

onMounted(async () => {
  if (await checkSupport()) fetchStatus()
  await fetchProfile()
  if (user.value?.calorie_goal) calorieGoal.value = user.value.calorie_goal
})

async function toggleBiometric() {
  if (bioBusy.value) return
  bioBusy.value = true
  try {
    if (bioEnabled.value) {
      await disablePasskey()
      success('Đã tắt đăng nhập bằng vân tay / Face ID')
    } else {
      const ok = await registerPasskey()
      if (ok) success('Đã bật đăng nhập bằng vân tay / Face ID')
      else toastError('Không thể đăng ký. Thiết bị từ chối hoặc chưa hỗ trợ.')
    }
  } finally {
    bioBusy.value = false
  }
}

function toggleDevice(id: string) {
  const d = connectedDevices.value.find(d => d.id === id)
  if (d) d.connected = !d.connected
}

function triggerAvatarPicker() {
  avatarPicker.value?.open()
}

async function onAvatarUpload(file: File) {
  avatarUploading.value = true
  try {
    const url = await uploadAvatar(file)
    if (url) success('Đã cập nhật ảnh đại diện')
    else toastError('Không thể tải ảnh lên. Thử lại sau.')
  } finally {
    avatarUploading.value = false
  }
}

async function handleDeleteAvatar() {
  const ok = await deleteAvatar()
  if (ok) success('Đã xoá ảnh đại diện')
}

async function handleLogout() {
  const { logout } = useAuth()
  logoutLoading.value = true
  try { await logout() } finally { logoutLoading.value = false }
}
</script>

<template>
  <div class="pb-8">
    <!-- Header -->
    <div class="px-5 pt-2 pb-4 animate-fadeInUp" style="opacity:0">
      <h1 class="text-[28px] font-bold text-black">Hồ sơ</h1>
    </div>

    <!-- Loading skeleton -->
    <template v-if="loading">
      <div class="mx-5 mb-4 rounded-[20px] bg-gray-200 animate-pulse h-44"/>
      <div class="mx-5 mb-2 rounded-[16px] bg-gray-200 animate-pulse h-14"/>
      <div class="mx-5 mb-2 rounded-[16px] bg-gray-200 animate-pulse h-36"/>
    </template>

    <template v-else>
      <!-- Profile card -->
      <div class="mx-5 mb-4 animate-fadeInUp delay-1" style="opacity:0">
        <div class="bg-gradient-to-br from-ios-blue to-ios-purple rounded-[20px] p-5 text-white">
          <div class="flex items-center gap-4">
            <!-- Avatar -->
            <div class="relative flex-shrink-0">
              <div class="w-16 h-16 rounded-full bg-white/25 overflow-hidden flex items-center justify-center">
                <img
                  v-if="user?.avatar_url"
                  :src="user.avatar_url"
                  class="w-full h-full object-cover"
                  alt="avatar"
                  @error="(e) => (e.target as HTMLImageElement).style.display = 'none'"
                />
                <span v-if="!user?.avatar_url" class="text-white font-bold text-[24px]">{{ displayAvatar }}</span>
              </div>
              <!-- Edit / uploading indicator -->
              <button
                class="absolute -bottom-1 -right-1 w-6 h-6 bg-white rounded-full flex items-center justify-center shadow-sm ios-press"
                :disabled="avatarUploading"
                @click="triggerAvatarPicker"
              >
                <svg v-if="avatarUploading" class="w-3.5 h-3.5 animate-spin text-ios-blue" viewBox="0 0 24 24" fill="none">
                  <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.3"/>
                  <path d="M12 2a10 10 0 0110 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                </svg>
                <svg v-else viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="#007AFF">
                  <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>
                </svg>
              </button>
            </div>

            <div class="flex-1 min-w-0">
              <h2 class="text-[18px] font-bold truncate">{{ displayName }}</h2>
              <p class="text-white/70 text-[13px] truncate">{{ displayEmail }}</p>
              <div class="flex gap-3 mt-2 flex-wrap">
                <span v-if="age" class="text-[12px] text-white/80">{{ age }} tuổi</span>
                <template v-if="age && (user?.height_cm || user?.weight_kg)">
                  <span class="text-white/40">·</span>
                </template>
                <span v-if="user?.height_cm" class="text-[12px] text-white/80">{{ user.height_cm }}cm</span>
                <template v-if="user?.height_cm && user?.weight_kg">
                  <span class="text-white/40">·</span>
                </template>
                <span v-if="user?.weight_kg" class="text-[12px] text-white/80">{{ user.weight_kg }}kg</span>
              </div>
            </div>
          </div>

          <!-- Stats strip -->
          <div class="flex gap-4 mt-4 pt-4 border-t border-white/20">
            <div class="flex-1 text-center">
              <p class="text-[20px] font-bold">{{ bmi ?? '—' }}</p>
              <p class="text-[11px] text-white/70">BMI</p>
              <p
                v-if="bmiLabel"
                class="text-[10px] font-semibold mt-0.5"
                :style="`color: ${bmiLabel.color}`"
              >{{ bmiLabel.text }}</p>
              <p v-else class="text-[10px] text-white/40 mt-0.5">Chưa có</p>
            </div>
            <div class="w-px bg-white/20"/>
            <div class="flex-1 text-center">
              <p class="text-[20px] font-bold">{{ bmr ? bmr.toLocaleString('vi') : '—' }}</p>
              <p class="text-[11px] text-white/70">BMR (kcal)</p>
              <p class="text-[10px] text-white/60 mt-0.5">Calo cơ bản</p>
            </div>
            <div class="w-px bg-white/20"/>
            <div class="flex-1 text-center">
              <p class="text-[20px] font-bold">{{ user?.calorie_streak ?? 0 }}</p>
              <p class="text-[11px] text-white/70">Ngày liên tiếp</p>
              <p class="text-[10px] text-white/60 mt-0.5">🔥 Streak</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Avatar picker sheet -->
      <ProfileAvatarPicker
        ref="avatarPicker"
        :current-url="user?.avatar_url ?? null"
        :uploading="avatarUploading"
        @upload="onAvatarUpload"
        @delete="handleDeleteAvatar"
      />

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
          <NuxtLink to="/settings/notifications" class="flex items-center gap-3 px-4 py-3.5 ios-press">
            <div class="w-8 h-8 rounded-[8px] bg-ios-green/10 flex items-center justify-center">
              <span class="text-base">🔔</span>
            </div>
            <span class="flex-1 text-left text-[15px] text-black">Cài đặt thông báo</span>
            <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#C7C7CC"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/></svg>
          </NuxtLink>
        </div>
      </div>

      <!-- Bảo mật (chỉ hiện nếu thiết bị hỗ trợ vân tay/Face ID) -->
      <div v-if="bioSupported" class="px-5 mb-2 animate-fadeInUp delay-3" style="opacity:0">
        <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-2 px-1">Bảo mật</p>
        <div class="bg-white rounded-[16px] overflow-hidden shadow-sm">
          <div class="flex items-center gap-3 px-4 py-3.5">
            <div class="w-8 h-8 rounded-[8px] bg-ios-purple/10 flex items-center justify-center">
              <span class="text-base">🔒</span>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-[15px] text-black">Đăng nhập bằng vân tay / Face ID</p>
              <p class="text-[12px] text-ios-gray">Đăng nhập nhanh lần sau, không cần mật khẩu</p>
            </div>
            <button
              role="switch"
              :aria-checked="bioEnabled"
              :disabled="bioBusy"
              class="relative w-[51px] h-[31px] rounded-full transition-colors flex-shrink-0 disabled:opacity-50"
              :class="bioEnabled ? 'bg-ios-green' : 'bg-ios-gray5'"
              @click="toggleBiometric"
            >
              <span
                class="absolute top-[2px] left-[2px] w-[27px] h-[27px] rounded-full bg-white shadow transition-transform"
                :class="bioEnabled ? 'translate-x-[20px]' : ''"
              />
            </button>
          </div>
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
          <NuxtLink to="/profile/edit" class="flex items-center gap-3 px-4 py-3.5 ios-press">
            <div class="w-8 h-8 rounded-[8px] bg-ios-blue/10 flex items-center justify-center">
              <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#007AFF"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
            </div>
            <span class="flex-1 text-left text-[15px] text-black">Chỉnh sửa hồ sơ</span>
            <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#C7C7CC"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/></svg>
          </NuxtLink>
          <div class="ios-separator mx-4"/>
          <NuxtLink to="/profile/change-password" class="flex items-center gap-3 px-4 py-3.5 ios-press">
            <div class="w-8 h-8 rounded-[8px] bg-ios-purple/10 flex items-center justify-center">
              <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#AF52DE"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
            </div>
            <span class="flex-1 text-left text-[15px] text-black">Đổi mật khẩu</span>
            <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#C7C7CC"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/></svg>
          </NuxtLink>
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

      <p class="text-center text-[11px] text-ios-gray mt-4">CaloEye v1.0.0 · Phiên bản thử nghiệm</p>
    </template>
  </div>
</template>
