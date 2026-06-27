<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { useAdmin } from '@/composables/useAdmin'
import { useAuth } from '@/composables/useAuth'
import { useToast } from '@/composables/useToast'
import type { AdminSettings } from '@/types/admin'

const { fetchSettings, saveSettings, testService } = useAdmin()
const { extractError } = useAuth()
const toast = useToast()

const settings = ref<AdminSettings | null>(null)
const loading = ref(true)
const saving = reactive<Record<string, boolean>>({})
const testing = reactive<Record<string, boolean>>({})

async function load() {
  loading.value = true
  try { settings.value = await fetchSettings() }
  catch (e) { toast.error(extractError(e) || 'Không tải được cấu hình') }
  finally { loading.value = false }
}

async function saveGroup(group: keyof AdminSettings) {
  if (!settings.value) return
  // maintenance_mode bật → xác nhận
  if (group === 'features' && settings.value.features.maintenance_mode) {
    if (!confirm('Bật chế độ bảo trì? App sẽ tạm ngưng với người dùng thường (admin vẫn truy cập được).')) return
  }
  saving[group] = true
  try {
    const payload = { [group]: settings.value[group] } as Partial<AdminSettings>
    settings.value = await saveSettings(payload)
    toast.success('Đã lưu cấu hình')
  } catch (e) {
    toast.error(extractError(e))
  } finally {
    saving[group] = false
  }
}

async function runTest(svc: 'ai' | 'fcm' | 'mail') {
  testing[svc] = true
  try {
    const res = await testService(svc)
    res.ok ? toast.success(res.message) : toast.error(res.message)
  } catch (e) {
    toast.error(extractError(e))
  } finally {
    testing[svc] = false
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-3xl">
    <h1 class="text-xl font-bold text-gray-900 mb-4">Cấu hình dịch vụ</h1>

    <div v-if="loading" class="text-gray-400 py-10 text-center">Đang tải…</div>

    <div v-else-if="settings" class="space-y-4">
      <!-- AI -->
      <section class="bg-white rounded-xl border border-gray-200 p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-gray-800">AI (Gemini)</h2>
          <button class="text-sm px-3 py-1.5 border border-gray-200 rounded-lg hover:bg-gray-50 disabled:opacity-50" :disabled="testing.ai" @click="runTest('ai')">
            {{ testing.ai ? 'Đang test…' : 'Test kết nối' }}
          </button>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <label class="block">
            <span class="text-xs text-gray-500">Model</span>
            <input v-model="settings.ai.model" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg" />
          </label>
          <label class="block">
            <span class="text-xs text-gray-500">API Key (để trống = giữ nguyên)</span>
            <input v-model="settings.ai.api_key" placeholder="••••••" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg font-mono" />
          </label>
          <label class="block">
            <span class="text-xs text-gray-500">Temperature: {{ settings.ai.temperature }}</span>
            <input v-model.number="settings.ai.temperature" type="range" min="0" max="2" step="0.1" class="mt-2 w-full" />
          </label>
          <label class="block">
            <span class="text-xs text-gray-500">Max tokens</span>
            <input v-model.number="settings.ai.max_tokens" type="number" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg" />
          </label>
        </div>
        <div class="flex gap-4 mt-3">
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="settings.ai.food_analysis_enabled" /> Bật phân tích món ăn</label>
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="settings.ai.chat_enabled" /> Bật AI chat</label>
        </div>
        <button class="mt-4 px-4 py-2 bg-calor-green text-white text-sm font-semibold rounded-lg hover:bg-calor-dark disabled:opacity-60" :disabled="saving.ai" @click="saveGroup('ai')">Lưu</button>
      </section>

      <!-- Rate limit -->
      <section class="bg-white rounded-xl border border-gray-200 p-5">
        <h2 class="font-semibold text-gray-800 mb-4">Giới hạn tần suất (req/phút)</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <label class="block">
            <span class="text-xs text-gray-500">Phân tích món</span>
            <input v-model.number="settings.rate_limit.food_analyze_per_min" type="number" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg" />
          </label>
          <label class="block">
            <span class="text-xs text-gray-500">Chat</span>
            <input v-model.number="settings.rate_limit.chat_per_min" type="number" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg" />
          </label>
          <label class="block">
            <span class="text-xs text-gray-500">Tạo kế hoạch</span>
            <input v-model.number="settings.rate_limit.plan_generate_per_min" type="number" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg" />
          </label>
        </div>
        <p class="text-[11px] text-gray-400 mt-2">Lưu ý: giá trị áp dụng ở lần triển khai limiter động; hiện mang tính định hướng.</p>
        <button class="mt-4 px-4 py-2 bg-calor-green text-white text-sm font-semibold rounded-lg hover:bg-calor-dark disabled:opacity-60" :disabled="saving.rate_limit" @click="saveGroup('rate_limit')">Lưu</button>
      </section>

      <!-- Notifications -->
      <section class="bg-white rounded-xl border border-gray-200 p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-gray-800">Thông báo</h2>
          <button class="text-sm px-3 py-1.5 border border-gray-200 rounded-lg hover:bg-gray-50 disabled:opacity-50" :disabled="testing.fcm" @click="runTest('fcm')">
            {{ testing.fcm ? 'Đang test…' : 'Test FCM' }}
          </button>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <label class="block">
            <span class="text-xs text-gray-500">Mặc định buổi sáng</span>
            <input v-model="settings.notifications.morning_default" type="time" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg" />
          </label>
          <label class="block">
            <span class="text-xs text-gray-500">Mặc định buổi tối</span>
            <input v-model="settings.notifications.evening_default" type="time" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg" />
          </label>
          <label class="block">
            <span class="text-xs text-gray-500">Re-engagement sau (ngày)</span>
            <input v-model.number="settings.notifications.reengagement_days" type="number" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg" />
          </label>
          <label class="block">
            <span class="text-xs text-gray-500">FCM project</span>
            <input :value="settings.notifications.fcm_project_id || '—'" disabled class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg bg-gray-100 text-gray-500" />
          </label>
        </div>
        <label class="flex items-center gap-2 text-sm mt-3"><input type="checkbox" v-model="settings.notifications.fcm_enabled" /> Bật push notification</label>
        <button class="mt-4 px-4 py-2 bg-calor-green text-white text-sm font-semibold rounded-lg hover:bg-calor-dark disabled:opacity-60" :disabled="saving.notifications" @click="saveGroup('notifications')">Lưu</button>
      </section>

      <!-- Mail -->
      <section class="bg-white rounded-xl border border-gray-200 p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-gray-800">Email</h2>
          <button class="text-sm px-3 py-1.5 border border-gray-200 rounded-lg hover:bg-gray-50 disabled:opacity-50" :disabled="testing.mail" @click="runTest('mail')">
            {{ testing.mail ? 'Đang gửi…' : 'Gửi mail test' }}
          </button>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <label class="block">
            <span class="text-xs text-gray-500">From address</span>
            <input v-model="settings.mail.from_address" type="email" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg" />
          </label>
          <label class="block">
            <span class="text-xs text-gray-500">From name</span>
            <input v-model="settings.mail.from_name" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg" />
          </label>
        </div>
        <label class="flex items-center gap-2 text-sm mt-3"><input type="checkbox" v-model="settings.mail.reengagement_enabled" /> Bật email re-engagement</label>
        <button class="mt-4 px-4 py-2 bg-calor-green text-white text-sm font-semibold rounded-lg hover:bg-calor-dark disabled:opacity-60" :disabled="saving.mail" @click="saveGroup('mail')">Lưu</button>
      </section>

      <!-- OAuth -->
      <section class="bg-white rounded-xl border border-gray-200 p-5">
        <h2 class="font-semibold text-gray-800 mb-4">Đăng nhập mạng xã hội</h2>
        <div class="space-y-2">
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="settings.oauth.google_enabled" /> Bật đăng nhập Google</label>
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="settings.oauth.facebook_enabled" /> Bật đăng nhập Facebook</label>
        </div>
        <button class="mt-4 px-4 py-2 bg-calor-green text-white text-sm font-semibold rounded-lg hover:bg-calor-dark disabled:opacity-60" :disabled="saving.oauth" @click="saveGroup('oauth')">Lưu</button>
      </section>

      <!-- Features -->
      <section class="bg-white rounded-xl border border-gray-200 p-5">
        <h2 class="font-semibold text-gray-800 mb-4">Tính năng</h2>
        <div class="space-y-2">
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="settings.features.registration_open" /> Cho phép đăng ký mới</label>
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="settings.features.guest_mode_enabled" /> Cho phép chế độ khách</label>
          <label class="flex items-center gap-2 text-sm text-red-600 font-medium"><input type="checkbox" v-model="settings.features.maintenance_mode" /> Chế độ bảo trì (tạm ngưng với người dùng thường)</label>
        </div>
        <button class="mt-4 px-4 py-2 bg-calor-green text-white text-sm font-semibold rounded-lg hover:bg-calor-dark disabled:opacity-60" :disabled="saving.features" @click="saveGroup('features')">Lưu</button>
      </section>
    </div>
  </div>
</template>
