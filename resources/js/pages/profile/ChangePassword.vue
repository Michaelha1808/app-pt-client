<script setup lang="ts">
const { extractError } = useAuth()
const { success } = useToast()
const router = useRouter()

const form = reactive({
  current_password: '',
  new_password: '',
  confirm_password: '',
})

const errors = reactive({
  current_password: '',
  new_password: '',
  confirm_password: '',
})

const showCurrent = ref(false)
const showNew = ref(false)
const showConfirm = ref(false)
const loading = ref(false)
const formError = ref('')

const passwordRe = /^(?=.*[A-Z])(?=.*\d).{8,}$/

function validate(): boolean {
  errors.current_password = ''
  errors.new_password = ''
  errors.confirm_password = ''
  let ok = true

  if (!form.current_password) {
    errors.current_password = 'Vui lòng nhập mật khẩu hiện tại'
    ok = false
  }

  if (!form.new_password) {
    errors.new_password = 'Vui lòng nhập mật khẩu mới'
    ok = false
  } else if (!passwordRe.test(form.new_password)) {
    errors.new_password = 'Mật khẩu tối thiểu 8 ký tự, có chữ hoa và số'
    ok = false
  } else if (form.new_password === form.current_password) {
    errors.new_password = 'Mật khẩu mới phải khác mật khẩu hiện tại'
    ok = false
  }

  if (!form.confirm_password) {
    errors.confirm_password = 'Vui lòng xác nhận mật khẩu mới'
    ok = false
  } else if (form.confirm_password !== form.new_password) {
    errors.confirm_password = 'Mật khẩu xác nhận không khớp'
    ok = false
  }

  return ok
}

async function handleSubmit() {
  if (!validate()) return
  loading.value = true
  formError.value = ''
  try {
    await apiFetch('/user/change-password', {
      method: 'POST',
      body: {
        current_password: form.current_password,
        new_password: form.new_password,
      },
    })
    success('Đã đổi mật khẩu thành công')
    router.back()
  } catch (err) {
    formError.value = extractError(err)
  } finally {
    loading.value = false
  }
}
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
      <h1 class="flex-1 text-[20px] font-bold text-black">Đổi mật khẩu</h1>
    </div>

    <div class="px-5">
      <!-- Description -->
      <div class="bg-calor-light/60 rounded-[14px] px-4 py-3 mb-5 flex gap-2.5 items-start">
        <svg viewBox="0 0 24 24" class="w-4 h-4 text-calor-green flex-shrink-0 mt-0.5" fill="currentColor">
          <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>
        </svg>
        <p class="text-[13px] text-calor-dark leading-snug">Mật khẩu mới phải có ít nhất 8 ký tự, bao gồm chữ hoa và số.</p>
      </div>

      <!-- Form -->
      <div class="bg-white rounded-[16px] overflow-hidden shadow-sm mb-4">
        <!-- Mật khẩu hiện tại -->
        <div class="px-4 py-3.5">
          <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-1">Mật khẩu hiện tại</label>
          <div class="flex items-center">
            <input
              v-model="form.current_password"
              :type="showCurrent ? 'text' : 'password'"
              placeholder="••••••••"
              autocomplete="current-password"
              class="flex-1 bg-transparent text-[16px] text-black placeholder-ios-gray3 outline-none"
              @input="errors.current_password = ''"
            />
            <button class="text-ios-gray ml-2 ios-press" @click="showCurrent = !showCurrent">
              <svg viewBox="0 0 24 24" class="w-5 h-5" fill="currentColor">
                <path v-if="showCurrent" d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
                <path v-else d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46A11.804 11.804 0 001 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/>
              </svg>
            </button>
          </div>
          <p v-if="errors.current_password" class="text-[12px] text-red-500 mt-1">{{ errors.current_password }}</p>
        </div>

        <div class="ios-separator mx-4"/>

        <!-- Mật khẩu mới -->
        <div class="px-4 py-3.5">
          <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-1">Mật khẩu mới</label>
          <div class="flex items-center">
            <input
              v-model="form.new_password"
              :type="showNew ? 'text' : 'password'"
              placeholder="••••••••"
              autocomplete="new-password"
              class="flex-1 bg-transparent text-[16px] text-black placeholder-ios-gray3 outline-none"
              @input="errors.new_password = ''"
            />
            <button class="text-ios-gray ml-2 ios-press" @click="showNew = !showNew">
              <svg viewBox="0 0 24 24" class="w-5 h-5" fill="currentColor">
                <path v-if="showNew" d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
                <path v-else d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46A11.804 11.804 0 001 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/>
              </svg>
            </button>
          </div>
          <p v-if="errors.new_password" class="text-[12px] text-red-500 mt-1">{{ errors.new_password }}</p>
        </div>

        <div class="ios-separator mx-4"/>

        <!-- Xác nhận mật khẩu mới -->
        <div class="px-4 py-3.5">
          <label class="text-[11px] font-semibold text-ios-gray uppercase tracking-wide block mb-1">Xác nhận mật khẩu mới</label>
          <div class="flex items-center">
            <input
              v-model="form.confirm_password"
              :type="showConfirm ? 'text' : 'password'"
              placeholder="••••••••"
              autocomplete="new-password"
              class="flex-1 bg-transparent text-[16px] text-black placeholder-ios-gray3 outline-none"
              @input="errors.confirm_password = ''"
            />
            <button class="text-ios-gray ml-2 ios-press" @click="showConfirm = !showConfirm">
              <svg viewBox="0 0 24 24" class="w-5 h-5" fill="currentColor">
                <path v-if="showConfirm" d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
                <path v-else d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46A11.804 11.804 0 001 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/>
              </svg>
            </button>
          </div>
          <p v-if="errors.confirm_password" class="text-[12px] text-red-500 mt-1">{{ errors.confirm_password }}</p>
        </div>
      </div>

      <!-- API error -->
      <div v-if="formError" class="bg-red-50 border border-red-200 rounded-[12px] px-4 py-3 flex items-start gap-2.5 mb-4">
        <svg viewBox="0 0 24 24" class="w-4 h-4 text-red-500 flex-shrink-0 mt-0.5" fill="currentColor">
          <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
        </svg>
        <p class="text-[13px] text-red-600 leading-snug">{{ formError }}</p>
      </div>

      <!-- Submit -->
      <button
        class="w-full h-[52px] rounded-[14px] text-white font-semibold text-[17px] flex items-center justify-center ios-press transition-opacity"
        :class="loading ? 'bg-calor-green/70' : 'bg-calor-green'"
        :disabled="loading"
        @click="handleSubmit"
      >
        <svg v-if="loading" class="w-5 h-5 animate-spin" viewBox="0 0 24 24" fill="none">
          <circle cx="12" cy="12" r="10" stroke="white" stroke-width="3" opacity="0.3"/>
          <path d="M12 2a10 10 0 0110 10" stroke="white" stroke-width="3" stroke-linecap="round"/>
        </svg>
        <span v-else>Đổi mật khẩu</span>
      </button>
    </div>
  </div>
</template>
