<script setup lang="ts">
import AdvisorySource from '@/components/common/AdvisorySource.vue'
import { usePublicConfig } from '@/composables/usePublicConfig'
import { useNutritionStandards, calculateNutrition, type Citation } from '@/composables/useNutritionStandards'
import type { PreferenceKind } from '@/types/preference'

const { register, loginWithGoogle, loginWithFacebook, extractError } = useAuth()
const { config, loadPublicConfig, flag } = usePublicConfig()
const { standards: nutritionStandards, load: loadStandards } = useNutritionStandards()

// Feature flags admin cấu hình runtime
const googleEnabled = computed(() => flag(c => c.oauth.google_enabled))
const facebookEnabled = computed(() => flag(c => c.oauth.facebook_enabled))
const registrationClosed = computed(() => config.value?.features.registration_open === false)

onMounted(() => { loadPublicConfig(); loadStandards() })

// Khi hồ sơ đủ dữ liệu → gọi backend tính BMR/TDEE/goal đề xuất theo mục tiêu
// user chọn. Chạy mỗi khi user đổi mức vận động hoặc mục tiêu → số hiển thị
// luôn khớp lựa chọn hiện tại, không cần bấm gì thêm.
async function refreshSuggestion() {
  if (!birthYear.value || !gender.value || !height.value || !weight.value) return
  const res = await calculateNutrition({
    birth_year:     Number(birthYear.value),
    gender:         gender.value as 'male' | 'female' | 'other',
    height_cm:      Number(height.value),
    weight_kg:      Number(weight.value),
    activity_level: activityLevel.value,
    goal:           goalType.value,
  })
  if (!res) return
  suggested.value          = res
  suggestedCitations.value = res.citations
  calorieGoal.value        = String(res.calorie_goal)
}

watch([birthYear, gender, height, weight, activityLevel, goalType], () => {
  if (step.value >= 2) refreshSuggestion()
})

const step = ref(1)
const totalSteps = 4
const skippedPersonalInfo = ref(false)

// Step 1
const email = ref('')
const password = ref('')
const confirmPassword = ref('')

// Step 2
const name = ref('')
const birthYear = ref('')
const gender = ref<'male' | 'female' | 'other' | ''>('')
const height = ref('')
const weight = ref('')
const activityLevel = ref<'sedentary' | 'light' | 'moderate' | 'active' | 'very_active'>('light')

// Step 3
const goalType = ref<'lose' | 'maintain' | 'gain'>('maintain')
const calorieGoal = ref('2000')
const suggestedCitations = ref<Citation[]>([])
const suggested = ref<{ bmr: number; tdee: number; calorie_goal: number; target_macros: { protein: number; carbs: number; fat: number }; water_target_ml: number } | null>(null)
const morningTime = ref('07:00')
const eveningTime = ref('21:00')

const loading = ref(false)
const formError = ref('')
const errors = reactive({
  email: '', password: '', confirmPassword: '',
  name: '', birthYear: '', gender: '', height: '', weight: '',
})

const stepTitles = ['Tạo tài khoản', 'Thông tin cá nhân', 'Mục tiêu của bạn', 'Sở thích ăn uống']
const stepSubtitles = [
  'Nhập email và mật khẩu để bắt đầu',
  'Giúp AI tính toán chính xác hơn',
  'Thiết lập chỉ tiêu calo hàng ngày',
  'Chọn nhanh để AVO gợi ý đúng gu hơn — có thể bỏ qua',
]

// Step 4 — quick-pick, không bắt buộc. Lưu qua endpoint /preferences có sẵn
// (giống trang /profile/preferences) — chỉ chạy được vì register() ở bước 3
// đã tạo phiên đăng nhập (token), nên gọi ngay được API cần auth.
interface PrefGroup { key: PreferenceKind; title: string; icon: string; options: string[] }
const prefGroups: PrefGroup[] = [
  { key: 'allergy', title: 'Dị ứng / kiêng tuyệt đối', icon: '🚫', options: ['Hải sản', 'Tôm', 'Đậu phộng', 'Sữa', 'Trứng', 'Gluten'] },
  { key: 'diet', title: 'Chế độ ăn', icon: '🥗', options: ['Ăn chay', 'Keto', 'Low-carb', 'Giảm cân', 'Tăng cơ'] },
  { key: 'dislike', title: 'Không thích ăn', icon: '👎', options: ['Nội tạng', 'Rau mùi', 'Mướp đắng', 'Sầu riêng'] },
  { key: 'like', title: 'Món khoái khẩu', icon: '❤️', options: ['Phở', 'Bún bò', 'Cơm gà', 'Rau xanh', 'Ức gà'] },
  { key: 'habit', title: 'Thói quen ăn uống', icon: '⏰', options: ['Hay bỏ bữa sáng', 'Ăn khuya', 'Ăn nhanh', 'Uống ít nước'] },
]

const selectedPrefs = ref<Set<string>>(new Set())
const prefKey = (kind: string, label: string) => `${kind}|${label}`
const isPrefSelected = (kind: PreferenceKind, label: string) => selectedPrefs.value.has(prefKey(kind, label))

function togglePref(kind: PreferenceKind, label: string) {
  const key = prefKey(kind, label)
  const next = new Set(selectedPrefs.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  selectedPrefs.value = next
}

async function finishPreferences() {
  loading.value = true
  try {
    if (selectedPrefs.value.size) {
      const { add } = usePreferences()
      const entries = Array.from(selectedPrefs.value).map((k) => {
        const [kind, label] = k.split('|') as [PreferenceKind, string]
        return { kind, label }
      })
      await Promise.all(entries.map(e => add(e.kind, e.label)))
    }
  } finally {
    loading.value = false
    navigateTo('/home')
  }
}

function skipPreferences() {
  navigateTo('/home')
}

const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function validateStep1(): boolean {
  errors.email = ''
  errors.password = ''
  errors.confirmPassword = ''
  let ok = true
  if (!email.value || !emailRe.test(email.value)) {
    errors.email = 'Email không hợp lệ'
    ok = false
  }
  if (password.value.length < 8) {
    errors.password = 'Mật khẩu tối thiểu 8 ký tự'
    ok = false
  } else if (!/[A-Z]/.test(password.value) || !/[0-9]/.test(password.value)) {
    errors.password = 'Cần có ít nhất 1 chữ hoa và 1 số'
    ok = false
  }
  if (!errors.password && password.value !== confirmPassword.value) {
    errors.confirmPassword = 'Mật khẩu xác nhận không khớp'
    ok = false
  }
  return ok
}

function validateStep2(): boolean {
  errors.name = ''
  errors.birthYear = ''
  errors.gender = ''
  errors.height = ''
  errors.weight = ''
  let ok = true
  if (name.value.trim().length < 2) {
    errors.name = 'Tên cần ít nhất 2 ký tự'
    ok = false
  }
  const yr = Number(birthYear.value)
  if (!birthYear.value || yr < 1900 || yr > 2015) {
    errors.birthYear = 'Năm sinh không hợp lệ (1900–2015)'
    ok = false
  }
  if (!gender.value) {
    errors.gender = 'Vui lòng chọn giới tính'
    ok = false
  }
  const h = Number(height.value)
  if (!height.value || h < 50 || h > 300) {
    errors.height = 'Chiều cao không hợp lệ (50–300 cm)'
    ok = false
  }
  const w = Number(weight.value)
  if (!weight.value || w < 20 || w > 500) {
    errors.weight = 'Cân nặng không hợp lệ (20–500 kg)'
    ok = false
  }
  return ok
}

function skipStep2() {
  skippedPersonalInfo.value = true
  // Fill defaults so API call stays valid
  if (!name.value) name.value = email.value.split('@')[0]
  if (!birthYear.value) birthYear.value = '2000'
  if (!gender.value) gender.value = 'other'
  if (!height.value) height.value = '170'
  if (!weight.value) weight.value = '65'
  // Clear any validation errors from partial input
  errors.name = ''
  errors.birthYear = ''
  errors.gender = ''
  errors.height = ''
  errors.weight = ''
  step.value++
}

async function nextStep() {
  if (step.value === 1) {
    if (!validateStep1()) return
    step.value++
    return
  }

  if (step.value === 2) {
    if (!validateStep2()) return
    step.value++
    return
  }

  if (step.value === 3) {
    // Tạo tài khoản ngay đây (không redirect) — bước 4 cần phiên đăng nhập
    // để lưu sở thích qua endpoint /preferences.
    loading.value = true
    formError.value = ''
    try {
      await register({
        email: email.value,
        password: password.value,
        name: name.value.trim(),
        birth_year: Number(birthYear.value),
        gender: gender.value as 'male' | 'female' | 'other',
        activity_level: activityLevel.value,
        height_cm: Number(height.value),
        weight_kg: Number(weight.value),
        calorie_goal: Number(calorieGoal.value),
        morning_notify: morningTime.value,
        evening_notify: eveningTime.value,
      }, { redirect: false })
      step.value++
    } catch (err) {
      formError.value = extractError(err)
    } finally {
      loading.value = false
    }
    return
  }

  // Step 4
  await finishPreferences()
}

function prevStep() {
  // Bước 4: tài khoản đã tạo xong, không còn "quay lại" thật sự — coi như bỏ qua.
  if (step.value === 4) {
    skipPreferences()
    return
  }
  if (step.value > 1) step.value--
  else navigateTo('/auth/login')
}

const genders = [
  { value: 'male', label: 'Nam', icon: '👨' },
  { value: 'female', label: 'Nữ', icon: '👩' },
  { value: 'other', label: 'Khác', icon: '🌈' },
]

// Preset mục tiêu — con số kcal được TÍNH từ TDEE (backend /nutrition/calculate),
// không hardcode. User chọn ý định (giảm/duy trì/tăng), số kcal tự cập nhật theo
// mức vận động + hồ sơ.
const goalOptions = [
  { value: 'lose'     as const, label: 'Giảm cân', icon: '🏃', desc: 'TDEE − 500 kcal (giảm ~0.5 kg/tuần)' },
  { value: 'maintain' as const, label: 'Duy trì', icon: '⚖️', desc: 'Bằng đúng TDEE' },
  { value: 'gain'     as const, label: 'Tăng cơ', icon: '💪', desc: 'TDEE + 300 kcal (tăng ~0.25 kg/tuần)' },
]

// Danh sách mức vận động lấy từ /nutrition/standards (đã cache) — luôn khớp
// với PAL backend dùng (không đồng bộ tay giữa 2 nơi).
const activityOptions = computed(() => {
  const list = nutritionStandards.value?.activity_levels
  if (!list) return []
  return Object.entries(list).map(([value, meta]) => ({ value, ...meta }))
})
</script>

<template>
  <div class="flex flex-col min-h-full">
    <!-- Nav bar -->
    <div class="flex items-center px-4 pt-2 pb-3">
      <button
        class="w-9 h-9 rounded-full bg-ios-gray6 flex items-center justify-center ios-press"
        @click="prevStep"
      >
        <svg viewBox="0 0 24 24" class="w-5 h-5" style="fill:var(--color-calor-green)">
          <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
        </svg>
      </button>

      <!-- Step progress dots -->
      <div class="flex-1 flex justify-center gap-2">
        <div
          v-for="i in totalSteps" :key="i"
          class="h-1.5 rounded-full transition-all duration-300"
          :class="i === step ? 'w-6 bg-ios-blue' : i < step ? 'w-1.5 bg-ios-blue' : 'w-1.5 bg-ios-gray5'"
        />
      </div>

      <!-- Skip button (step 2 personal info, step 4 preferences) -->
      <button
        v-if="step === 2"
        class="h-9 px-2 flex items-center gap-1 ios-press whitespace-nowrap"
        @click="skipStep2"
      >
        <span class="text-[14px] text-ios-blue font-semibold">Bỏ qua</span>
        <svg viewBox="0 0 24 24" class="w-4 h-4" fill="none" style="stroke:var(--color-calor-green)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 6l6 6-6 6"/>
        </svg>
      </button>
      <button
        v-else-if="step === 4"
        class="h-9 px-2 flex items-center gap-1 ios-press whitespace-nowrap"
        @click="skipPreferences"
      >
        <span class="text-[14px] text-ios-blue font-semibold">Bỏ qua</span>
        <svg viewBox="0 0 24 24" class="w-4 h-4" fill="none" style="stroke:var(--color-calor-green)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 6l6 6-6 6"/>
        </svg>
      </button>
      <div v-else class="w-9 h-9"/>
    </div>

    <!-- Header -->
    <div class="px-6 mb-5 animate-fadeInUp" style="opacity:0">
      <h2 class="text-[26px] font-bold text-black">{{ stepTitles[step - 1] }}</h2>
      <p class="text-[14px] text-ios-gray mt-1">{{ stepSubtitles[step - 1] }}</p>
    </div>

    <!-- Đăng ký đang tạm khoá (admin tắt trong Settings) -->
    <div v-if="registrationClosed" class="mx-6 mb-4 bg-amber-50 border border-amber-200 rounded-[14px] px-4 py-3 flex items-start gap-2.5">
      <svg viewBox="0 0 24 24" class="w-5 h-5 text-amber-500 flex-shrink-0" fill="currentColor">
        <path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/>
      </svg>
      <p class="text-[13px] text-amber-700 leading-snug">
        Đăng ký tài khoản mới hiện đang tạm khoá. Vui lòng quay lại sau hoặc đăng nhập bằng tài khoản có sẵn.
      </p>
    </div>

    <!-- Step 1: Account -->
    <div v-if="step === 1" class="px-6 flex flex-col gap-3 animate-fadeInUp delay-1" style="opacity:0">
      <!-- Email -->
      <div>
        <div
          class="bg-ios-gray6 rounded-[14px] px-4 py-3.5 border transition-colors"
          :class="errors.email ? 'border-red-400' : 'border-transparent'"
        >
          <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-1">Email</label>
          <input
            v-model="email" type="email" placeholder="ten@email.com"
            class="w-full bg-transparent text-[16px] text-black placeholder-ios-gray3 outline-none"
            @input="errors.email = ''"
          />
        </div>
        <p v-if="errors.email" class="text-[12px] text-red-500 mt-1 px-1">{{ errors.email }}</p>
      </div>

      <!-- Password -->
      <div>
        <div
          class="bg-ios-gray6 rounded-[14px] px-4 py-3.5 border transition-colors"
          :class="errors.password ? 'border-red-400' : 'border-transparent'"
        >
          <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-1">Mật khẩu</label>
          <input
            v-model="password" type="password" placeholder="Tối thiểu 8 ký tự, có chữ hoa và số"
            class="w-full bg-transparent text-[16px] text-black placeholder-ios-gray3 outline-none"
            @input="errors.password = ''"
          />
        </div>
        <p v-if="errors.password" class="text-[12px] text-red-500 mt-1 px-1">{{ errors.password }}</p>
      </div>

      <!-- Confirm password -->
      <div>
        <div
          class="bg-ios-gray6 rounded-[14px] px-4 py-3.5 border transition-colors"
          :class="errors.confirmPassword ? 'border-red-400' : 'border-transparent'"
        >
          <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-1">Xác nhận mật khẩu</label>
          <input
            v-model="confirmPassword" type="password" placeholder="Nhập lại mật khẩu"
            class="w-full bg-transparent text-[16px] text-black placeholder-ios-gray3 outline-none"
            @input="errors.confirmPassword = ''"
          />
        </div>
        <p v-if="errors.confirmPassword" class="text-[12px] text-red-500 mt-1 px-1">{{ errors.confirmPassword }}</p>
      </div>

      <!-- Divider -->
      <div v-if="googleEnabled || facebookEnabled" class="flex items-center gap-3 mt-2">
        <div class="flex-1 h-px bg-ios-gray5"/>
        <span class="text-[12px] text-ios-gray">hoặc đăng ký với</span>
        <div class="flex-1 h-px bg-ios-gray5"/>
      </div>

      <!-- Social shortcuts (round icon only) -->
      <div v-if="googleEnabled || facebookEnabled" class="flex justify-center gap-4">
        <button
          v-if="googleEnabled"
          aria-label="Đăng ký với Google"
          class="w-12 h-12 rounded-full bg-white border border-ios-gray5 shadow-sm flex items-center justify-center ios-press"
          @click="loginWithGoogle"
        >
          <svg viewBox="0 0 24 24" class="w-6 h-6">
            <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
            <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
            <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
            <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
          </svg>
        </button>

        <button
          v-if="facebookEnabled"
          aria-label="Đăng ký với Facebook"
          class="w-12 h-12 rounded-full bg-[#1877F2] shadow-sm flex items-center justify-center ios-press"
          @click="loginWithFacebook"
        >
          <svg viewBox="0 0 24 24" class="w-6 h-6" fill="white">
            <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073c0 6.024 4.388 11.018 10.125 11.927v-8.437H7.078v-3.49h3.047V9.412c0-3.017 1.791-4.683 4.533-4.683 1.313 0 2.686.235 2.686.235v2.971h-1.513c-1.491 0-1.956.93-1.956 1.886v2.262h3.328l-.532 3.49h-2.796V24C19.612 23.091 24 18.097 24 12.073z"/>
          </svg>
        </button>

        <button
          disabled
          aria-label="Đăng ký với Apple (chưa hỗ trợ)"
          class="w-12 h-12 rounded-full bg-black shadow-sm flex items-center justify-center opacity-40 cursor-not-allowed"
        >
          <svg viewBox="0 0 24 24" class="w-6 h-6" fill="white">
            <path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/>
          </svg>
        </button>
      </div>
    </div>

    <!-- Step 2: Personal info -->
    <div v-if="step === 2" class="px-6 flex flex-col gap-3 animate-fadeInUp delay-1" style="opacity:0">
      <!-- Name -->
      <div>
        <div
          class="bg-ios-gray6 rounded-[14px] px-4 py-3.5 border transition-colors"
          :class="errors.name ? 'border-red-400' : 'border-transparent'"
        >
          <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-1">Họ và tên</label>
          <input
            v-model="name" type="text" placeholder="Nguyễn Văn A"
            class="w-full bg-transparent text-[16px] text-black placeholder-ios-gray3 outline-none"
            @input="errors.name = ''"
          />
        </div>
        <p v-if="errors.name" class="text-[12px] text-red-500 mt-1 px-1">{{ errors.name }}</p>
      </div>

      <!-- Birth year + Gender -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <div
            class="bg-ios-gray6 rounded-[14px] px-4 py-3.5 border transition-colors"
            :class="errors.birthYear ? 'border-red-400' : 'border-transparent'"
          >
            <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-1">Năm sinh</label>
            <input
              v-model="birthYear" type="number" placeholder="2000"
              class="w-full bg-transparent text-[16px] text-black placeholder-ios-gray3 outline-none"
              @input="errors.birthYear = ''"
            />
          </div>
          <p v-if="errors.birthYear" class="text-[12px] text-red-500 mt-1 px-1 leading-tight">{{ errors.birthYear }}</p>
        </div>
        <div>
          <div
            class="bg-ios-gray6 rounded-[14px] px-4 py-3.5 border transition-colors"
            :class="errors.gender ? 'border-red-400' : 'border-transparent'"
          >
            <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-1">Giới tính</label>
            <select
              v-model="gender"
              class="w-full bg-transparent text-[16px] text-black outline-none appearance-none"
              @change="errors.gender = ''"
            >
              <option value="" disabled>Chọn</option>
              <option v-for="g in genders" :key="g.value" :value="g.value">{{ g.icon }} {{ g.label }}</option>
            </select>
          </div>
          <p v-if="errors.gender" class="text-[12px] text-red-500 mt-1 px-1">{{ errors.gender }}</p>
        </div>
      </div>

      <!-- Height + Weight -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <div
            class="bg-ios-gray6 rounded-[14px] px-4 py-3.5 border transition-colors"
            :class="errors.height ? 'border-red-400' : 'border-transparent'"
          >
            <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-1">Chiều cao (cm)</label>
            <input
              v-model="height" type="number" placeholder="170"
              class="w-full bg-transparent text-[16px] text-black placeholder-ios-gray3 outline-none"
              @input="errors.height = ''"
            />
          </div>
          <p v-if="errors.height" class="text-[12px] text-red-500 mt-1 px-1 leading-tight">{{ errors.height }}</p>
        </div>
        <div>
          <div
            class="bg-ios-gray6 rounded-[14px] px-4 py-3.5 border transition-colors"
            :class="errors.weight ? 'border-red-400' : 'border-transparent'"
          >
            <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-1">Cân nặng (kg)</label>
            <input
              v-model="weight" type="number" placeholder="65"
              class="w-full bg-transparent text-[16px] text-black placeholder-ios-gray3 outline-none"
              @input="errors.weight = ''"
            />
          </div>
          <p v-if="errors.weight" class="text-[12px] text-red-500 mt-1 px-1 leading-tight">{{ errors.weight }}</p>
        </div>
      </div>

      <!-- Mức vận động — quyết định hệ số PAL để tính TDEE đúng cho user
           (thay vì cứng "vận động nhẹ" cho mọi người) -->
      <div v-if="activityOptions.length" class="flex flex-col gap-1.5">
        <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide px-1">Mức vận động</p>
        <div
          v-for="opt in activityOptions" :key="opt.value"
          class="bg-white rounded-[14px] px-4 py-3 flex items-center gap-3 ios-press border transition-colors"
          :class="activityLevel === opt.value ? 'border-ios-blue' : 'border-transparent'"
          @click="activityLevel = opt.value as 'sedentary' | 'light' | 'moderate' | 'active' | 'very_active'"
        >
          <div class="flex-1">
            <p class="text-[14px] font-semibold text-black">{{ opt.label }}</p>
            <p class="text-[12px] text-ios-gray leading-tight">{{ opt.desc }}</p>
          </div>
          <div
            class="w-5 h-5 rounded-full border-2 flex items-center justify-center flex-shrink-0"
            :class="activityLevel === opt.value ? 'border-ios-blue bg-ios-blue' : 'border-ios-gray4'"
          >
            <svg v-if="activityLevel === opt.value" viewBox="0 0 24 24" class="w-3 h-3" fill="white">
              <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
            </svg>
          </div>
        </div>
      </div>

      <!-- Skip hint -->
      <p class="text-[12px] text-ios-gray text-center mt-1">
        Bạn có thể cập nhật thông tin này sau trong phần Hồ sơ
      </p>
    </div>

    <!-- Step 3: Goals -->
    <div v-if="step === 3" class="px-6 flex flex-col gap-4 animate-fadeInUp delay-1" style="opacity:0">
      <!-- Goal picker — số kcal tự tính từ TDEE, không hardcode -->
      <div class="flex flex-col gap-2">
        <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide px-1">Mục tiêu sức khỏe</p>
        <div
          v-for="opt in goalOptions" :key="opt.value"
          class="bg-white rounded-[14px] px-4 py-4 flex items-center gap-3 ios-press border-2 transition-colors"
          :class="goalType === opt.value ? 'border-ios-blue' : 'border-transparent'"
          @click="goalType = opt.value"
        >
          <div
            class="w-10 h-10 rounded-full flex items-center justify-center"
            :class="goalType === opt.value ? 'bg-ios-blue/10' : 'bg-ios-gray6'"
          >
            <span class="text-xl">{{ opt.icon }}</span>
          </div>
          <div class="flex-1">
            <p class="text-[15px] font-semibold text-black">{{ opt.label }}</p>
            <p class="text-[12px] text-ios-gray leading-tight">{{ opt.desc }}</p>
          </div>
          <div
            class="w-5 h-5 rounded-full border-2 flex items-center justify-center"
            :class="goalType === opt.value ? 'border-ios-blue bg-ios-blue' : 'border-ios-gray4'"
          >
            <svg v-if="goalType === opt.value" viewBox="0 0 24 24" class="w-3 h-3" fill="white">
              <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
            </svg>
          </div>
        </div>
      </div>

      <!-- Số đề xuất từ backend — hiển thị để user tin cậy, không phải AI đoán -->
      <div v-if="suggested" class="rounded-[14px] bg-calor-light/60 border border-calor-mint/40 px-4 py-3 flex flex-col gap-2">
        <div class="flex items-baseline justify-between">
          <span class="text-[13px] text-calor-deep font-semibold">Calo đề xuất</span>
          <span class="text-[22px] font-bold text-calor-deep">{{ suggested.calorie_goal.toLocaleString('vi') }} <span class="text-[13px] font-medium">kcal/ngày</span></span>
        </div>
        <div class="flex justify-between text-[11px] text-ios-gray">
          <span>BMR {{ suggested.bmr }} · TDEE {{ suggested.tdee }}</span>
          <span>{{ suggested.target_macros.protein }}P · {{ suggested.target_macros.carbs }}C · {{ suggested.target_macros.fat }}F · {{ suggested.water_target_ml }}ml nước</span>
        </div>
        <AdvisorySource :citations="suggestedCitations" compact />
      </div>

      <!-- Notification times -->
      <div class="flex flex-col gap-2">
        <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide px-1">Thời gian thông báo</p>
        <div class="bg-white rounded-[14px] overflow-hidden">
          <div class="flex items-center justify-between px-4 py-3.5">
            <div class="flex items-center gap-3">
              <span class="text-xl">🌅</span>
              <span class="text-[15px] text-black">Lời chào buổi sáng</span>
            </div>
            <input
              v-model="morningTime" type="time"
              class="text-[15px] text-ios-blue font-medium outline-none bg-transparent"
            />
          </div>
          <div class="ios-separator mx-4"/>
          <div class="flex items-center justify-between px-4 py-3.5">
            <div class="flex items-center gap-3">
              <span class="text-xl">🌙</span>
              <span class="text-[15px] text-black">Tổng kết cuối ngày</span>
            </div>
            <input
              v-model="eveningTime" type="time"
              class="text-[15px] text-ios-blue font-medium outline-none bg-transparent"
            />
          </div>
        </div>
      </div>
    </div>

    <!-- Step 4: Preferences (bỏ qua được, chỉnh sửa lại sau trong Hồ sơ) -->
    <div v-if="step === 4" class="px-6 flex flex-col gap-4 animate-fadeInUp delay-1" style="opacity:0">
      <div v-for="group in prefGroups" :key="group.key" class="flex flex-col gap-2">
        <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide px-1">{{ group.icon }} {{ group.title }}</p>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="opt in group.options" :key="opt"
            type="button"
            class="px-3.5 py-2 rounded-full text-[13px] font-medium border-2 ios-press transition-colors"
            :class="isPrefSelected(group.key, opt) ? 'bg-ios-blue border-ios-blue text-white' : 'bg-ios-gray6 border-transparent text-black'"
            @click="togglePref(group.key, opt)"
          >{{ opt }}</button>
        </div>
      </div>

      <p class="text-[12px] text-ios-gray text-center mt-1">
        Có thể chỉnh sửa đầy đủ sau trong Hồ sơ &gt; Sở thích ăn uống
      </p>
    </div>

    <!-- CTA -->
    <div class="px-6 mt-auto pt-6 pb-6">
      <!-- API error -->
      <div v-if="formError" class="mb-3 bg-red-50 border border-red-200 rounded-[12px] px-4 py-3 flex items-start gap-2.5">
        <svg viewBox="0 0 24 24" class="w-4 h-4 text-red-500 flex-shrink-0 mt-0.5" fill="currentColor">
          <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
        </svg>
        <p class="text-[13px] text-red-600 leading-snug">{{ formError }}</p>
      </div>

      <button
        class="w-full h-[52px] bg-ios-blue rounded-[14px] text-white font-semibold text-[17px] flex items-center justify-center ios-press transition-opacity"
        :disabled="loading"
        :class="loading ? 'opacity-70' : ''"
        @click="nextStep"
      >
        <svg v-if="loading" class="w-5 h-5 animate-spin" viewBox="0 0 24 24" fill="none">
          <circle cx="12" cy="12" r="10" stroke="white" stroke-width="3" opacity="0.3"/>
          <path d="M12 2a10 10 0 0110 10" stroke="white" stroke-width="3" stroke-linecap="round"/>
        </svg>
        <span v-else>{{ step === totalSteps ? 'Hoàn tất' : 'Tiếp theo' }}</span>
      </button>

      <div class="mt-4 flex justify-center">
        <div class="flex gap-1">
          <span class="text-[13px] text-ios-gray">Đã có tài khoản?</span>
          <NuxtLink to="/auth/login" class="text-[13px] text-ios-blue font-semibold">Đăng nhập</NuxtLink>
        </div>
      </div>
    </div>
  </div>
</template>
