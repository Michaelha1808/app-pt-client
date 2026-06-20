<script setup lang="ts">
import ProfileAvatarPicker from '@/components/profile/AvatarPicker.vue'

const { user } = useAuth()
const { saving, error: profileError, saveProfile, uploadAvatar, deleteAvatar } = useProfile()
const { success, error: toastError } = useToast()
const router = useRouter()

// ── Form state ────────────────────────────────────────────────────────────────
const form = reactive({
  name:           user.value?.name ?? '',
  birth_year:     user.value?.birth_year ?? new Date().getFullYear() - 25,
  gender:         (user.value?.gender ?? 'male') as 'male' | 'female' | 'other',
  height_cm:      user.value?.height_cm ?? 170,
  weight_kg:      user.value?.weight_kg ?? 65,
  calorie_goal:   user.value?.calorie_goal ?? 2000,
  morning_notify: user.value?.morning_notify ?? '07:00',
  evening_notify: user.value?.evening_notify ?? '21:00',
})

const errors = reactive({
  name: '',
  birth_year: '',
  height_cm: '',
  weight_kg: '',
})

// ── Dirty check ───────────────────────────────────────────────────────────────
const isDirty = computed(() => {
  const u = user.value
  if (!u) return false
  return (
    form.name           !== u.name            ||
    form.birth_year     !== (u.birth_year ?? form.birth_year)   ||
    form.gender         !== (u.gender ?? form.gender)            ||
    form.height_cm      !== (u.height_cm ?? form.height_cm)     ||
    form.weight_kg      !== (u.weight_kg ?? form.weight_kg)      ||
    form.calorie_goal   !== (u.calorie_goal ?? form.calorie_goal)||
    form.morning_notify !== (u.morning_notify ?? form.morning_notify) ||
    form.evening_notify !== (u.evening_notify ?? form.evening_notify)
  )
})

// ── Avatar ────────────────────────────────────────────────────────────────────
const avatarPicker = ref<{ open: () => void } | null>(null)
const avatarUploading = ref(false)
const localAvatarPreview = ref<string | null>(null)

async function onAvatarUpload(file: File) {
  avatarUploading.value = true
  localAvatarPreview.value = URL.createObjectURL(file)
  try {
    const url = await uploadAvatar(file)
    if (url) success('Đã cập nhật ảnh đại diện')
    else {
      toastError('Không thể tải ảnh lên')
      localAvatarPreview.value = null
    }
  } finally {
    avatarUploading.value = false
  }
}

async function handleDeleteAvatar() {
  const ok = await deleteAvatar()
  if (ok) {
    localAvatarPreview.value = null
    success('Đã xoá ảnh đại diện')
  }
}

// ── Computed avatar src ───────────────────────────────────────────────────────
const avatarSrc = computed(() => localAvatarPreview.value ?? user.value?.avatar_url ?? null)
const avatarInitial = computed(() => (user.value?.name ?? 'U').charAt(0).toUpperCase())

// ── Validation ────────────────────────────────────────────────────────────────
function validate(): boolean {
  errors.name = ''
  errors.birth_year = ''
  errors.height_cm = ''
  errors.weight_kg = ''
  let ok = true

  if (!form.name.trim()) {
    errors.name = 'Vui lòng nhập họ và tên'
    ok = false
  } else if (form.name.trim().length < 2) {
    errors.name = 'Tên phải có ít nhất 2 ký tự'
    ok = false
  }

  const yr = Number(form.birth_year)
  if (!yr || yr < 1900 || yr > 2015) {
    errors.birth_year = 'Năm sinh phải từ 1900 đến 2015'
    ok = false
  }

  const h = Number(form.height_cm)
  if (!h || h < 50 || h > 300) {
    errors.height_cm = 'Chiều cao phải từ 50 đến 300 cm'
    ok = false
  }

  const w = Number(form.weight_kg)
  if (!w || w < 20 || w > 500) {
    errors.weight_kg = 'Cân nặng phải từ 20 đến 500 kg'
    ok = false
  }

  return ok
}

// ── Submit ────────────────────────────────────────────────────────────────────
async function handleSave() {
  if (!validate()) return
  const ok = await saveProfile({
    name:           form.name.trim(),
    birth_year:     Number(form.birth_year),
    gender:         form.gender,
    height_cm:      Number(form.height_cm),
    weight_kg:      Number(form.weight_kg),
    calorie_goal:   form.calorie_goal,
    morning_notify: form.morning_notify,
    evening_notify: form.evening_notify,
  })
  if (ok) {
    success('Đã lưu thông tin')
    router.back()
  }
}

// ── Field input helper ─────────────────────────────────────────────────────
const genderOptions = [
  { value: 'male',   label: 'Nam' },
  { value: 'female', label: 'Nữ' },
  { value: 'other',  label: 'Khác' },
]
</script>

<template>
  <div class="pb-10">
    <!-- Header -->
    <div class="flex items-center gap-3 px-5 pt-2 pb-4">
      <button class="ios-press -ml-1 p-1" @click="router.back()">
        <svg viewBox="0 0 24 24" class="w-6 h-6" fill="#007AFF">
          <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
        </svg>
      </button>
      <h1 class="flex-1 text-[20px] font-bold text-black">Chỉnh sửa hồ sơ</h1>
      <button
        class="px-4 py-1.5 rounded-[10px] text-[15px] font-semibold transition-all ios-press"
        :class="isDirty && !saving
          ? 'bg-calor-green text-white'
          : 'bg-ios-gray5 text-ios-gray3'"
        :disabled="!isDirty || saving"
        @click="handleSave"
      >
        <svg v-if="saving" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none">
          <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.3"/>
          <path d="M12 2a10 10 0 0110 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        </svg>
        <span v-else>Lưu</span>
      </button>
    </div>

    <!-- API error -->
    <div v-if="profileError" class="mx-5 mb-3 bg-red-50 border border-red-200 rounded-[12px] px-4 py-3 flex items-start gap-2">
      <svg viewBox="0 0 24 24" class="w-4 h-4 text-red-500 flex-shrink-0 mt-0.5" fill="currentColor">
        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
      </svg>
      <p class="text-[13px] text-red-600 leading-snug">{{ profileError }}</p>
    </div>

    <!-- Section 1: Avatar -->
    <div class="px-5 mb-5">
      <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-3 px-1">Ảnh đại diện</p>
      <div class="flex items-center gap-4">
        <!-- Avatar preview -->
        <div class="relative flex-shrink-0">
          <div class="w-20 h-20 rounded-full bg-gradient-to-br from-ios-blue to-ios-purple overflow-hidden flex items-center justify-center">
            <img
              v-if="avatarSrc"
              :src="avatarSrc"
              class="w-full h-full object-cover"
              alt="avatar"
              @error="(e) => (e.target as HTMLImageElement).style.display = 'none'"
            />
            <span v-if="!avatarSrc" class="text-white font-bold text-[28px]">{{ avatarInitial }}</span>
          </div>
          <div
            v-if="avatarUploading"
            class="absolute inset-0 rounded-full bg-black/30 flex items-center justify-center"
          >
            <svg class="w-6 h-6 animate-spin text-white" viewBox="0 0 24 24" fill="none">
              <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.3"/>
              <path d="M12 2a10 10 0 0110 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
            </svg>
          </div>
        </div>

        <div class="flex flex-col gap-2">
          <button
            class="px-4 py-2 bg-ios-blue/10 rounded-[10px] text-ios-blue text-[14px] font-semibold ios-press"
            :disabled="avatarUploading"
            @click="avatarPicker?.open()"
          >Thay đổi ảnh</button>
        </div>
      </div>

      <!-- Avatar picker sheet -->
      <ProfileAvatarPicker
        ref="avatarPicker"
        :current-url="avatarSrc"
        :uploading="avatarUploading"
        @upload="onAvatarUpload"
        @delete="handleDeleteAvatar"
      />
    </div>

    <!-- Section 2: Thông tin cơ bản -->
    <div class="px-5 mb-4">
      <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-2 px-1">Thông tin cơ bản</p>
      <div class="bg-white rounded-[16px] overflow-hidden shadow-sm">
        <!-- Họ và tên -->
        <div class="px-4 py-3.5">
          <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-1">Họ và tên</label>
          <input
            v-model="form.name"
            type="text"
            placeholder="Nhập họ và tên"
            class="w-full bg-transparent text-[16px] text-black placeholder-ios-gray3 outline-none"
            @input="errors.name = ''"
          />
          <p v-if="errors.name" class="text-[12px] text-red-500 mt-1">{{ errors.name }}</p>
        </div>
        <div class="ios-separator mx-4"/>
        <!-- Email (readonly) -->
        <div class="px-4 py-3.5">
          <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-1">Email</label>
          <p class="text-[16px] text-ios-gray3">{{ user?.email }}</p>
          <p class="text-[11px] text-ios-gray mt-0.5">Email không thể thay đổi</p>
        </div>
      </div>
    </div>

    <!-- Section 3: Thể chất -->
    <div class="px-5 mb-4">
      <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-2 px-1">Thể chất</p>
      <div class="bg-white rounded-[16px] overflow-hidden shadow-sm">

        <!-- Giới tính -->
        <div class="px-4 py-3.5">
          <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-2">Giới tính</label>
          <div class="flex gap-2">
            <button
              v-for="opt in genderOptions"
              :key="opt.value"
              class="flex-1 py-2 rounded-[10px] text-[14px] font-semibold transition-colors ios-press"
              :class="form.gender === opt.value
                ? 'bg-calor-green text-white'
                : 'bg-ios-gray5 text-ios-gray'"
              @click="form.gender = opt.value as 'male' | 'female' | 'other'"
            >{{ opt.label }}</button>
          </div>
        </div>
        <div class="ios-separator mx-4"/>

        <!-- Năm sinh -->
        <div class="px-4 py-3.5">
          <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-1">Năm sinh</label>
          <input
            v-model.number="form.birth_year"
            type="number"
            placeholder="VD: 1998"
            min="1900"
            max="2015"
            class="w-full bg-transparent text-[16px] text-black placeholder-ios-gray3 outline-none"
            @input="errors.birth_year = ''"
          />
          <p v-if="errors.birth_year" class="text-[12px] text-red-500 mt-1">{{ errors.birth_year }}</p>
        </div>
        <div class="ios-separator mx-4"/>

        <!-- Chiều cao -->
        <div class="px-4 py-3.5">
          <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-1">Chiều cao</label>
          <div class="flex items-center">
            <input
              v-model.number="form.height_cm"
              type="number"
              placeholder="170"
              min="50"
              max="300"
              class="flex-1 bg-transparent text-[16px] text-black placeholder-ios-gray3 outline-none"
              @input="errors.height_cm = ''"
            />
            <span class="text-[15px] text-ios-gray ml-2">cm</span>
          </div>
          <p v-if="errors.height_cm" class="text-[12px] text-red-500 mt-1">{{ errors.height_cm }}</p>
        </div>
        <div class="ios-separator mx-4"/>

        <!-- Cân nặng -->
        <div class="px-4 py-3.5">
          <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-1">Cân nặng</label>
          <div class="flex items-center">
            <input
              v-model.number="form.weight_kg"
              type="number"
              placeholder="65"
              min="20"
              max="500"
              class="flex-1 bg-transparent text-[16px] text-black placeholder-ios-gray3 outline-none"
              @input="errors.weight_kg = ''"
            />
            <span class="text-[15px] text-ios-gray ml-2">kg</span>
          </div>
          <p v-if="errors.weight_kg" class="text-[12px] text-red-500 mt-1">{{ errors.weight_kg }}</p>
        </div>
      </div>
    </div>

    <!-- Section 4: Mục tiêu -->
    <div class="px-5 mb-4">
      <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-2 px-1">Mục tiêu</p>
      <div class="bg-white rounded-[16px] overflow-hidden shadow-sm">
        <div class="flex items-center gap-3 px-4 py-3.5">
          <div class="w-8 h-8 rounded-[8px] bg-ios-orange/15 flex items-center justify-center">
            <span class="text-lg">🎯</span>
          </div>
          <p class="flex-1 text-[15px] text-black">Calo mục tiêu / ngày</p>
          <div class="flex items-center gap-1.5">
            <button
              class="w-7 h-7 rounded-full bg-ios-gray5 flex items-center justify-center ios-press"
              @click="form.calorie_goal = Math.max(1000, form.calorie_goal - 100)"
            >
              <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#8E8E93"><path d="M19 13H5v-2h14v2z"/></svg>
            </button>
            <span class="text-[15px] font-semibold text-ios-blue w-16 text-center">{{ form.calorie_goal.toLocaleString('vi') }}</span>
            <button
              class="w-7 h-7 rounded-full bg-ios-gray5 flex items-center justify-center ios-press"
              @click="form.calorie_goal = Math.min(5000, form.calorie_goal + 100)"
            >
              <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#8E8E93"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Section 5: Thông báo -->
    <div class="px-5 mb-4">
      <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-2 px-1">Thông báo</p>
      <div class="bg-white rounded-[16px] overflow-hidden shadow-sm">
        <div class="flex items-center gap-3 px-4 py-3.5">
          <span class="text-lg w-6 text-center">🌅</span>
          <span class="flex-1 text-[15px] text-black">Nhắc buổi sáng</span>
          <input
            v-model="form.morning_notify"
            type="time"
            class="bg-transparent text-[15px] text-ios-blue font-semibold outline-none"
          />
        </div>
        <div class="ios-separator mx-4"/>
        <div class="flex items-center gap-3 px-4 py-3.5">
          <span class="text-lg w-6 text-center">🌙</span>
          <span class="flex-1 text-[15px] text-black">Nhắc buổi tối</span>
          <input
            v-model="form.evening_notify"
            type="time"
            class="bg-transparent text-[15px] text-ios-blue font-semibold outline-none"
          />
        </div>
      </div>
    </div>

    <!-- Bottom save button -->
    <div class="px-5">
      <button
        class="w-full h-[52px] rounded-[14px] text-white font-semibold text-[17px] flex items-center justify-center transition-opacity ios-press"
        :class="isDirty && !saving ? 'bg-calor-green' : 'bg-calor-green/40'"
        :disabled="!isDirty || saving"
        @click="handleSave"
      >
        <svg v-if="saving" class="w-5 h-5 animate-spin" viewBox="0 0 24 24" fill="none">
          <circle cx="12" cy="12" r="10" stroke="white" stroke-width="3" opacity="0.3"/>
          <path d="M12 2a10 10 0 0110 10" stroke="white" stroke-width="3" stroke-linecap="round"/>
        </svg>
        <span v-else>Lưu thay đổi</span>
      </button>
    </div>
  </div>
</template>
