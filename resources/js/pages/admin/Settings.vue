<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { useAdmin } from '@/composables/useAdmin'
import { useAuth } from '@/composables/useAuth'
import { useToast } from '@/composables/useToast'
import type { AdminSettings } from '@/types/admin'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import { Loader2 } from 'lucide-vue-next'

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
    <h1 class="text-xl font-bold mb-4">Cấu hình dịch vụ</h1>

    <div v-if="loading" class="space-y-4">
      <Skeleton v-for="i in 4" :key="i" class="h-48 rounded-xl" />
    </div>

    <div v-else-if="settings" class="space-y-4">
      <!-- AI -->
      <Card class="gap-4">
        <CardHeader class="flex-row items-center justify-between">
          <CardTitle class="text-base">AI (Gemini)</CardTitle>
          <Button variant="outline" size="sm" :disabled="testing.ai" @click="runTest('ai')">
            <Loader2 v-if="testing.ai" class="w-3.5 h-3.5 animate-spin" />
            {{ testing.ai ? 'Đang test…' : 'Test kết nối' }}
          </Button>
        </CardHeader>
        <CardContent>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="space-y-1.5">
              <Label>Model</Label>
              <Input v-model="settings.ai.model" />
            </div>
            <div class="space-y-1.5">
              <Label>API Key (để trống = giữ nguyên)</Label>
              <Input v-model="settings.ai.api_key" placeholder="••••••" class="font-mono" />
            </div>
            <div class="space-y-1.5">
              <Label>Temperature: {{ settings.ai.temperature }}</Label>
              <input v-model.number="settings.ai.temperature" type="range" min="0" max="2" step="0.1" class="mt-2 w-full accent-primary" />
            </div>
            <div class="space-y-1.5">
              <Label>Max tokens</Label>
              <Input v-model.number="settings.ai.max_tokens" type="number" />
            </div>
          </div>
          <div class="flex flex-wrap gap-x-6 gap-y-2 mt-4">
            <label class="flex items-center gap-2 text-sm"><Switch v-model="settings.ai.food_analysis_enabled" /> Bật phân tích món ăn</label>
            <label class="flex items-center gap-2 text-sm"><Switch v-model="settings.ai.chat_enabled" /> Bật AI chat</label>
          </div>
          <Button class="mt-4" :disabled="saving.ai" @click="saveGroup('ai')">
            <Loader2 v-if="saving.ai" class="w-4 h-4 animate-spin" /> Lưu
          </Button>
        </CardContent>
      </Card>

      <!-- Rate limit -->
      <Card class="gap-4">
        <CardHeader><CardTitle class="text-base">Giới hạn tần suất (req/phút)</CardTitle></CardHeader>
        <CardContent>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="space-y-1.5">
              <Label>Phân tích món</Label>
              <Input v-model.number="settings.rate_limit.food_analyze_per_min" type="number" />
            </div>
            <div class="space-y-1.5">
              <Label>Chat</Label>
              <Input v-model.number="settings.rate_limit.chat_per_min" type="number" />
            </div>
            <div class="space-y-1.5">
              <Label>Tạo kế hoạch</Label>
              <Input v-model.number="settings.rate_limit.plan_generate_per_min" type="number" />
            </div>
          </div>
          <p class="text-[11px] text-muted-foreground mt-2">Lưu ý: giá trị áp dụng ở lần triển khai limiter động; hiện mang tính định hướng.</p>
          <Button class="mt-4" :disabled="saving.rate_limit" @click="saveGroup('rate_limit')">
            <Loader2 v-if="saving.rate_limit" class="w-4 h-4 animate-spin" /> Lưu
          </Button>
        </CardContent>
      </Card>

      <!-- Notifications -->
      <Card class="gap-4">
        <CardHeader class="flex-row items-center justify-between">
          <CardTitle class="text-base">Thông báo</CardTitle>
          <Button variant="outline" size="sm" :disabled="testing.fcm" @click="runTest('fcm')">
            <Loader2 v-if="testing.fcm" class="w-3.5 h-3.5 animate-spin" />
            {{ testing.fcm ? 'Đang test…' : 'Test FCM' }}
          </Button>
        </CardHeader>
        <CardContent>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="space-y-1.5">
              <Label>Mặc định buổi sáng</Label>
              <Input v-model="settings.notifications.morning_default" type="time" />
            </div>
            <div class="space-y-1.5">
              <Label>Mặc định buổi tối</Label>
              <Input v-model="settings.notifications.evening_default" type="time" />
            </div>
            <div class="space-y-1.5">
              <Label>Re-engagement sau (ngày)</Label>
              <Input v-model.number="settings.notifications.reengagement_days" type="number" />
            </div>
            <div class="space-y-1.5">
              <Label>FCM project</Label>
              <Input :model-value="settings.notifications.fcm_project_id || '—'" disabled />
            </div>
          </div>
          <label class="flex items-center gap-2 text-sm mt-4"><Switch v-model="settings.notifications.fcm_enabled" /> Bật push notification</label>
          <Button class="mt-4" :disabled="saving.notifications" @click="saveGroup('notifications')">
            <Loader2 v-if="saving.notifications" class="w-4 h-4 animate-spin" /> Lưu
          </Button>
        </CardContent>
      </Card>

      <!-- Mail -->
      <Card class="gap-4">
        <CardHeader class="flex-row items-center justify-between">
          <CardTitle class="text-base">Email</CardTitle>
          <Button variant="outline" size="sm" :disabled="testing.mail" @click="runTest('mail')">
            <Loader2 v-if="testing.mail" class="w-3.5 h-3.5 animate-spin" />
            {{ testing.mail ? 'Đang gửi…' : 'Gửi mail test' }}
          </Button>
        </CardHeader>
        <CardContent>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="space-y-1.5">
              <Label>From address</Label>
              <Input v-model="settings.mail.from_address" type="email" />
            </div>
            <div class="space-y-1.5">
              <Label>From name</Label>
              <Input v-model="settings.mail.from_name" />
            </div>
          </div>
          <label class="flex items-center gap-2 text-sm mt-4"><Switch v-model="settings.mail.reengagement_enabled" /> Bật email re-engagement</label>
          <Button class="mt-4" :disabled="saving.mail" @click="saveGroup('mail')">
            <Loader2 v-if="saving.mail" class="w-4 h-4 animate-spin" /> Lưu
          </Button>
        </CardContent>
      </Card>

      <!-- OAuth -->
      <Card class="gap-4">
        <CardHeader><CardTitle class="text-base">Đăng nhập mạng xã hội</CardTitle></CardHeader>
        <CardContent>
          <div class="space-y-3">
            <label class="flex items-center gap-2 text-sm"><Switch v-model="settings.oauth.google_enabled" /> Bật đăng nhập Google</label>
            <label class="flex items-center gap-2 text-sm"><Switch v-model="settings.oauth.facebook_enabled" /> Bật đăng nhập Facebook</label>
          </div>
          <Button class="mt-4" :disabled="saving.oauth" @click="saveGroup('oauth')">
            <Loader2 v-if="saving.oauth" class="w-4 h-4 animate-spin" /> Lưu
          </Button>
        </CardContent>
      </Card>

      <!-- Features -->
      <Card class="gap-4">
        <CardHeader><CardTitle class="text-base">Tính năng</CardTitle></CardHeader>
        <CardContent>
          <div class="space-y-3">
            <label class="flex items-center gap-2 text-sm"><Switch v-model="settings.features.registration_open" /> Cho phép đăng ký mới</label>
            <label class="flex items-center gap-2 text-sm"><Switch v-model="settings.features.guest_mode_enabled" /> Cho phép chế độ khách</label>
            <label class="flex items-center gap-2 text-sm text-destructive font-medium">
              <Switch v-model="settings.features.maintenance_mode" class="data-[state=checked]:bg-destructive" />
              Chế độ bảo trì (tạm ngưng với người dùng thường)
            </label>
          </div>
          <Button class="mt-4" :disabled="saving.features" @click="saveGroup('features')">
            <Loader2 v-if="saving.features" class="w-4 h-4 animate-spin" /> Lưu
          </Button>
        </CardContent>
      </Card>
    </div>
  </div>
</template>
