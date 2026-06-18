<script setup lang="ts">
definePageMeta({ layout: 'auth', middleware: 'guest' })

const { register, loginWithGoogle, extractError } = useAuth()

const step = ref(1)
const totalSteps = 3

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

// Step 3
const calorieGoal = ref('2000')
const morningTime = ref('07:00')
const eveningTime = ref('21:00')

const loading = ref(false)
const formError = ref('')
const errors = reactive({
  email: '', password: '', confirmPassword: '',
  name: '', birthYear: '', gender: '', height: '', weight: '',
})

const stepTitles = ['Tạo tài khoản', 'Thông tin cá nhân', 'Mục tiêu của bạn']
const stepSubtitles = [
  'Nhập email và mật khẩu để bắt đầu',
  'Giúp AI hiểu bạn tốt hơn',
  'Thiết lập chỉ tiêu calo hàng ngày',
]

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

async function nextStep() {
  if (step.value === 1 && !validateStep1()) return
  if (step.value === 2 && !validateStep2()) return

  if (step.value < totalSteps) {
    step.value++
    return
  }

  loading.value = true
  formError.value = ''
  try {
    await register({
      email: email.value,
      password: password.value,
      name: name.value.trim(),
      birth_year: Number(birthYear.value),
      gender: gender.value as 'male' | 'female' | 'other',
      height_cm: Number(height.value),
      weight_kg: Number(weight.value),
      calorie_goal: Number(calorieGoal.value),
      morning_notify: morningTime.value,
      evening_notify: eveningTime.value,
    })
  } catch (err) {
    formError.value = extractError(err)
  } finally {
    loading.value = false
  }
}

function prevStep() {
  if (step.value > 1) step.value--
  else navigateTo('/auth/login')
}

const genders = [
  { value: 'male', label: 'Nam' },
  { value: 'female', label: 'Nữ' },
  { value: 'other', label: 'Khác' },
]

const caloriePresets = [
  { label: 'Giảm cân', value: '1500', desc: '1,500 kcal/ngày' },
  { label: 'Duy trì', value: '2000', desc: '2,000 kcal/ngày' },
  { label: 'Tăng cơ', value: '2500', desc: '2,500 kcal/ngày' },
]
</script>

<template>
  <div class="flex flex-col min-h-full">
    <!-- Nav bar -->
    <div class="flex items-center px-4 pt-2 pb-3">
      <button
        class="w-9 h-9 rounded-full bg-ios-gray6 flex items-center justify-center ios-press"
        @click="prevStep"
      >
        <svg viewBox="0 0 24 24" class="w-5 h-5" fill="#007AFF">
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
      <div class="w-9 h-9"/>
    </div>

    <!-- Header -->
    <div class="px-6 mb-6 animate-fadeInUp" style="opacity:0">
      <h2 class="text-[26px] font-bold text-black">{{ stepTitles[step - 1] }}</h2>
      <p class="text-[14px] text-ios-gray mt-1">{{ stepSubtitles[step - 1] }}</p>
    </div>

    <!-- Step 1: Account -->
    <div v-if="step === 1" class="px-6 flex flex-col gap-3 animate-fadeInUp delay-1" style="opacity:0">
      <!-- Google shortcut -->
      <button
        class="w-full h-[48px] bg-white border border-ios-gray5 rounded-[14px] flex items-center justify-center gap-2.5 ios-press"
        @click="loginWithGoogle"
      >
        <svg viewBox="0 0 24 24" class="w-5 h-5">
          <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
          <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
          <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
          <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
        </svg>
        <span class="text-black font-semibold text-[15px]">Đăng ký nhanh với Google</span>
      </button>

      <div class="flex items-center gap-3">
        <div class="flex-1 h-px bg-ios-gray5"/>
        <span class="text-[12px] text-ios-gray">hoặc dùng email</span>
        <div class="flex-1 h-px bg-ios-gray5"/>
      </div>

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
              <option v-for="g in genders" :key="g.value" :value="g.value">{{ g.label }}</option>
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
    </div>

    <!-- Step 3: Goals -->
    <div v-if="step === 3" class="px-6 flex flex-col gap-4 animate-fadeInUp delay-1" style="opacity:0">
      <!-- Calorie presets -->
      <div class="flex flex-col gap-2">
        <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide px-1">Mục tiêu sức khỏe</p>
        <div
          v-for="preset in caloriePresets" :key="preset.value"
          class="bg-white rounded-[14px] px-4 py-4 flex items-center gap-3 ios-press border-2 transition-colors"
          :class="calorieGoal === preset.value ? 'border-ios-blue' : 'border-transparent'"
          @click="calorieGoal = preset.value"
        >
          <div
            class="w-10 h-10 rounded-full flex items-center justify-center"
            :class="calorieGoal === preset.value ? 'bg-ios-blue/10' : 'bg-ios-gray6'"
          >
            <span class="text-xl">
              {{ preset.label === 'Giảm cân' ? '🏃' : preset.label === 'Duy trì' ? '⚖️' : '💪' }}
            </span>
          </div>
          <div class="flex-1">
            <p class="text-[15px] font-semibold text-black">{{ preset.label }}</p>
            <p class="text-[13px] text-ios-gray">{{ preset.desc }}</p>
          </div>
          <div
            class="w-5 h-5 rounded-full border-2 flex items-center justify-center"
            :class="calorieGoal === preset.value ? 'border-ios-blue bg-ios-blue' : 'border-ios-gray4'"
          >
            <svg v-if="calorieGoal === preset.value" viewBox="0 0 24 24" class="w-3 h-3" fill="white">
              <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
            </svg>
          </div>
        </div>
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

    <!-- CTA -->
    <div class="px-6 mt-8">
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
    </div>

    <div class="mt-6 mb-4 flex justify-center">
      <div class="flex gap-1">
        <span class="text-[14px] text-ios-gray">Đã có tài khoản?</span>
        <NuxtLink to="/auth/login" class="text-[14px] text-ios-blue font-semibold">Đăng nhập</NuxtLink>
      </div>
    </div>
  </div>
</template>
