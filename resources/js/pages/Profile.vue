<script setup lang="ts">
import ProfileAvatarPicker from '@/components/profile/AvatarPicker.vue'

// Version lấy từ nguồn duy nhất (package.json → Vite define). Xem vite.config.js.
const appVersion = __APP_VERSION__

const { user } = useAuth()
const { loading, bmi, bmr, bmiLabel, age, fetchProfile, uploadAvatar, deleteAvatar } = useProfile()
const { streakCount, fetchStreak } = useStreak()
const { isConnected, isAvailable, fetchConnections, connect, disconnect } = useHealthIntegration()
const { success, error: toastError } = useToast()
const stravaConnecting = ref(false)
const { supported: bioSupported, enabled: bioEnabled, checkSupport, fetchStatus, register: registerPasskey, disable: disablePasskey } = usePasskey()
const bioBusy = ref(false)

const avatarPicker = ref<{ open: () => void } | null>(null)
const avatarUploading = ref(false)
const logoutLoading = ref(false)
const calorieGoal = ref(2000)

const darkMode = ref(false)


const isAdmin = computed(() => user.value?.role === 'admin')
const displayName = computed(() => user.value?.name ?? 'Người dùng')
const displayEmail = computed(() => user.value?.email ?? '')
const displayAvatar = computed(() => displayName.value.charAt(0).toUpperCase())

onMounted(async () => {
  if (await checkSupport()) fetchStatus()
  fetchStreak()
  fetchConnections()
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

async function connectStrava() {
  stravaConnecting.value = true
  try {
    await connect('strava')   // redirect cả tab sang Strava
  } catch {
    stravaConnecting.value = false
    toastError('Không thể bắt đầu kết nối Strava')
  }
}

async function disconnectStrava() {
  if (!confirm('Ngắt kết nối Strava?')) return
  const ok = await disconnect('strava')
  if (ok) success('Đã ngắt kết nối Strava')
  else toastError('Không thể ngắt kết nối')
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
    <!-- Loading skeleton -->
    <template v-if="loading">
      <div class="mx-5 mb-4 rounded-[24px] bg-gray-200 animate-pulse h-52"/>
      <div class="mx-5 mb-3 rounded-[18px] bg-gray-200 animate-pulse h-20"/>
      <div class="mx-5 grid grid-cols-2 gap-3">
        <div class="rounded-[18px] bg-gray-200 animate-pulse h-24"/>
        <div class="rounded-[18px] bg-gray-200 animate-pulse h-24"/>
        <div class="rounded-[18px] bg-gray-200 animate-pulse h-24"/>
        <div class="rounded-[18px] bg-gray-200 animate-pulse h-24"/>
      </div>
    </template>

    <template v-else>
      <!-- ══ Curved gradient header ══ -->
      <div class="relative overflow-hidden text-white text-center rounded-b-[34px] px-5 pt-10 pb-[92px] bg-gradient-to-b from-[#8bab77] to-[#5e7a54] animate-fadeInUp" style="opacity:0">
        <div class="absolute -left-4 -top-2 text-[110px] leading-none opacity-[0.12] pointer-events-none select-none">🥑</div>
        <div class="relative inline-block">
          <div class="w-[84px] h-[84px] rounded-full bg-white/25 ring-[3px] ring-white/40 overflow-hidden flex items-center justify-center">
            <img
              v-if="user?.avatar_url"
              :src="user.avatar_url"
              class="w-full h-full object-cover"
              alt="avatar"
              @error="(e) => (e.target as HTMLImageElement).style.display = 'none'"
            />
            <span v-if="!user?.avatar_url" class="font-display text-white font-bold text-[30px]">{{ displayAvatar }}</span>
          </div>
          <button
            class="absolute -bottom-1 -right-1 w-8 h-8 bg-white rounded-full flex items-center justify-center shadow-md ios-press"
            :disabled="avatarUploading"
            @click="triggerAvatarPicker"
          >
            <svg v-if="avatarUploading" class="w-4 h-4 animate-spin text-calor-green" viewBox="0 0 24 24" fill="none">
              <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.3"/>
              <path d="M12 2a10 10 0 0110 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
            </svg>
            <svg v-else viewBox="0 0 24 24" class="w-4 h-4" fill="#7c9a70">
              <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>
            </svg>
          </button>
        </div>
        <div class="relative mt-2.5 flex items-center justify-center gap-2">
          <h2 class="font-display text-[19px] font-bold truncate">{{ displayName }}</h2>
          <span v-if="isAdmin" class="text-[10px] font-bold bg-white/25 rounded-full px-2 py-0.5 flex-shrink-0">ADMIN</span>
        </div>
        <p class="relative text-white/80 text-[12px] truncate">{{ displayEmail }}<template v-if="age"> · {{ age }} tuổi</template></p>
      </div>

      <!-- Floating stat tiles -->
      <div class="relative -mt-[54px] mx-5 flex gap-2.5 animate-fadeInUp delay-1" style="opacity:0">
        <div class="flex-1 bg-white rounded-[18px] py-3.5 text-center shadow-[0_10px_22px_rgba(60,74,52,0.1)]">
          <p class="font-display text-[19px] font-bold text-calor-deep leading-none">{{ bmi ?? '—' }}</p>
          <p class="text-[10px] text-ios-gray mt-1">BMI</p>
          <p v-if="bmiLabel" class="text-[9px] font-semibold" :style="`color:${bmiLabel.color}`">{{ bmiLabel.text }}</p>
          <p v-else class="text-[9px] text-ios-gray2">Chưa có</p>
        </div>
        <div class="flex-1 bg-white rounded-[18px] py-3.5 text-center shadow-[0_10px_22px_rgba(60,74,52,0.1)]">
          <p class="font-display text-[19px] font-bold text-calor-deep leading-none">{{ bmr ? bmr.toLocaleString('vi') : '—' }}</p>
          <p class="text-[10px] text-ios-gray mt-1">BMR</p>
          <p class="text-[9px] text-ios-gray2">Calo cơ bản</p>
        </div>
        <div class="flex-1 bg-white rounded-[18px] py-3.5 text-center shadow-[0_10px_22px_rgba(60,74,52,0.1)]">
          <p class="font-display text-[19px] font-bold text-calor-deep leading-none">{{ streakCount }}</p>
          <p class="text-[10px] text-ios-gray mt-1">Streak</p>
          <p class="text-[9px] text-[#d98c5f]">🔥 ngày</p>
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

      <!-- ══ Mục tiêu calo (card nổi bật, giữ stepper) ══ -->
      <div class="mx-5 mt-4 mb-3 animate-fadeInUp delay-2" style="opacity:0">
        <div class="bg-white rounded-[18px] px-4 py-3.5 shadow-sm flex items-center gap-3">
          <div class="w-11 h-11 rounded-[13px] bg-gradient-to-br from-ios-orange/20 to-ios-yellow/20 flex items-center justify-center text-xl flex-shrink-0">🎯</div>
          <div class="flex-1 min-w-0">
            <p class="text-[12px] text-ios-gray">Calo mục tiêu / ngày</p>
            <p class="text-[22px] font-bold text-black leading-tight">{{ calorieGoal.toLocaleString('vi') }} <span class="text-[13px] font-medium text-ios-gray">kcal</span></p>
          </div>
          <div class="flex items-center gap-1.5 flex-shrink-0">
            <button
              class="w-8 h-8 rounded-full bg-ios-gray6 flex items-center justify-center ios-press"
              @click="calorieGoal = Math.max(1200, calorieGoal - 100)"
            >
              <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#8a9a7d"><path d="M19 13H5v-2h14v2z"/></svg>
            </button>
            <button
              class="w-8 h-8 rounded-full bg-calor-green flex items-center justify-center ios-press"
              @click="calorieGoal = Math.min(4000, calorieGoal + 100)"
            >
              <svg viewBox="0 0 24 24" class="w-4 h-4" fill="white"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            </button>
          </div>
        </div>
      </div>

      <!-- ══ Lưới ô lối tắt ══ -->
      <div class="mx-5 mb-4 grid grid-cols-2 gap-3 animate-fadeInUp delay-2" style="opacity:0">
        <!-- Cân nặng -->
        <NuxtLink to="/weight" class="bg-white rounded-[18px] p-4 shadow-sm ios-press flex flex-col gap-2.5">
          <div class="w-11 h-11 rounded-[13px] bg-ios-blue/12 flex items-center justify-center text-xl">⚖️</div>
          <div class="min-w-0">
            <p class="text-[14px] font-semibold text-black">Cân nặng</p>
            <p class="text-[12px] text-ios-gray truncate">{{ user?.weight_kg ? `Hiện tại ${user.weight_kg} kg` : 'Theo dõi tiến trình' }}</p>
          </div>
        </NuxtLink>

        <!-- Sở thích -->
        <NuxtLink to="/profile/preferences" class="bg-white rounded-[18px] p-4 shadow-sm ios-press flex flex-col gap-2.5">
          <div class="w-11 h-11 rounded-[13px] bg-ios-pink/12 flex items-center justify-center text-xl">🍽️</div>
          <div class="min-w-0">
            <p class="text-[14px] font-semibold text-black">Sở thích</p>
            <p class="text-[12px] text-ios-gray truncate">Dị ứng, chế độ ăn</p>
          </div>
        </NuxtLink>

        <!-- Thông báo -->
        <NuxtLink to="/settings/notifications" class="bg-white rounded-[18px] p-4 shadow-sm ios-press flex flex-col gap-2.5">
          <div class="w-11 h-11 rounded-[13px] bg-ios-green/12 flex items-center justify-center text-xl">🔔</div>
          <div class="min-w-0">
            <p class="text-[14px] font-semibold text-black">Thông báo</p>
            <p class="text-[12px] text-ios-gray truncate">Nhắc nhở, cập nhật</p>
          </div>
        </NuxtLink>

        <!-- Hiển thị -->
        <NuxtLink to="/settings/display" class="bg-white rounded-[18px] p-4 shadow-sm ios-press flex flex-col gap-2.5">
          <div class="w-11 h-11 rounded-[13px] bg-ios-purple/12 flex items-center justify-center text-xl">🔠</div>
          <div class="min-w-0">
            <p class="text-[14px] font-semibold text-black">Hiển thị</p>
            <p class="text-[12px] text-ios-gray truncate">Cỡ chữ, icon bay</p>
          </div>
        </NuxtLink>
      </div>

      <!-- ══ Kết nối sức khoẻ ══ -->
      <div class="px-5 mb-3 animate-fadeInUp delay-3" style="opacity:0">
        <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-2 px-1">Kết nối sức khoẻ</p>
        <div class="bg-white rounded-[18px] overflow-hidden shadow-sm">
          <!-- Strava -->
          <div class="flex items-center gap-3 px-4 py-3.5">
            <div class="w-9 h-9 rounded-[10px] flex items-center justify-center text-lg" style="background: #FC4C0218">🏃</div>
            <div class="flex-1 min-w-0">
              <span class="text-[15px] text-black">Strava</span>
              <p v-if="isConnected('strava')" class="text-[12px] text-ios-gray mt-0.5">Đã kết nối · tự đồng bộ buổi tập</p>
            </div>
            <button
              v-if="isConnected('strava')"
              class="px-3 py-1 rounded-full text-[12px] font-semibold bg-ios-red/10 text-ios-red ios-press"
              @click="disconnectStrava"
            >Ngắt kết nối</button>
            <button
              v-else-if="isAvailable('strava')"
              class="px-3 py-1 rounded-full text-[12px] font-semibold bg-ios-blue/10 text-ios-blue ios-press disabled:opacity-50"
              :disabled="stravaConnecting"
              @click="connectStrava"
            >{{ stravaConnecting ? '...' : 'Kết nối' }}</button>
            <span v-else class="text-[12px] text-ios-gray bg-ios-gray6 px-2.5 py-1 rounded-full">Sắp ra mắt</span>
          </div>

          <div class="ios-separator mx-4"/>

          <!-- Link sang feed hoạt động + log thủ công -->
          <NuxtLink to="/activities" class="flex items-center gap-3 px-4 py-3.5 ios-press">
            <div class="w-9 h-9 rounded-[10px] bg-ios-orange/12 flex items-center justify-center text-lg">📋</div>
            <span class="flex-1 text-[15px] text-black">Nhật ký hoạt động</span>
            <svg viewBox="0 0 24 24" class="w-4 h-4 text-ios-gray3" fill="currentColor"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
          </NuxtLink>
        </div>
        <p class="text-[12px] text-ios-gray mt-2 px-1 leading-snug">
          Không có Strava? Bạn vẫn tự thêm buổi tập trong Nhật ký hoạt động để cộng calo đốt.
        </p>
      </div>

      <!-- ══ Bảo mật (chỉ hiện nếu thiết bị hỗ trợ vân tay/Face ID) ══ -->
      <div v-if="bioSupported" class="px-5 mb-3 animate-fadeInUp delay-3" style="opacity:0">
        <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-2 px-1">Bảo mật</p>
        <div class="bg-white rounded-[18px] overflow-hidden shadow-sm">
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

      <!-- ══ Quản trị (chỉ hiện với admin) ══ -->
      <div v-if="isAdmin" class="px-5 mb-3 animate-fadeInUp delay-4" style="opacity:0">
        <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-2 px-1">Quản trị</p>
        <div class="bg-white rounded-[18px] overflow-hidden shadow-sm">
          <NuxtLink to="/admin" class="flex items-center gap-3 px-4 py-3.5 ios-press">
            <div class="w-8 h-8 rounded-[8px] bg-calor-green/10 flex items-center justify-center">
              <span class="text-base">🛠️</span>
            </div>
            <span class="flex-1 text-left text-[15px] text-black">Trang quản trị</span>
            <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#b8c0ac"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/></svg>
          </NuxtLink>
        </div>
      </div>

      <!-- ══ Tài khoản ══ -->
      <div class="px-5 mb-2 animate-fadeInUp delay-4" style="opacity:0">
        <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-2 px-1">Tài khoản</p>
        <div class="bg-white rounded-[18px] overflow-hidden shadow-sm">
          <NuxtLink v-if="!user?.email_verified" to="/profile/verify-email" class="flex items-center gap-3 px-4 py-3.5 ios-press">
            <div class="w-8 h-8 rounded-[8px] bg-ios-orange/10 flex items-center justify-center">
              <span class="text-base">✉️</span>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-[15px] text-black">Xác thực email</p>
              <p class="text-[12px] text-ios-orange">Chưa xác thực — bấm để xác thực ngay</p>
            </div>
            <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#b8c0ac"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/></svg>
          </NuxtLink>
          <div v-if="!user?.email_verified" class="ios-separator mx-4"/>
          <NuxtLink to="/profile/edit" class="flex items-center gap-3 px-4 py-3.5 ios-press">
            <div class="w-8 h-8 rounded-[8px] bg-ios-blue/10 flex items-center justify-center">
              <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#7c9a70"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
            </div>
            <span class="flex-1 text-left text-[15px] text-black">Chỉnh sửa hồ sơ</span>
            <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#b8c0ac"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/></svg>
          </NuxtLink>
          <div class="ios-separator mx-4"/>
          <NuxtLink to="/profile/change-password" class="flex items-center gap-3 px-4 py-3.5 ios-press">
            <div class="w-8 h-8 rounded-[8px] bg-ios-purple/10 flex items-center justify-center">
              <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#a58fb0"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
            </div>
            <span class="flex-1 text-left text-[15px] text-black">Đổi mật khẩu</span>
            <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#b8c0ac"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/></svg>
          </NuxtLink>
        </div>
      </div>

      <!-- Logout -->
      <div class="px-5 mt-4 animate-fadeInUp delay-5" style="opacity:0">
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

      <p class="text-center text-[11px] text-ios-gray mt-4">CaloEye v{{ appVersion }} · Phiên bản thử nghiệm</p>
    </template>
  </div>
</template>
