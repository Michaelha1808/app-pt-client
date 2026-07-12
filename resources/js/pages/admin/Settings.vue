<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useAdmin } from '@/composables/useAdmin'
import { useAuth } from '@/composables/useAuth'
import { useToast } from '@/composables/useToast'
import type { AdminSettings } from '@/types/admin'
import SystemPanel from '@/components/admin/SystemPanel.vue'
import {
  AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
  AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Bot, Gauge, Bell, Mail, KeyRound, SlidersHorizontal, Server,
  Loader2, RotateCcw, Save, AlertTriangle, PlugZap,
} from 'lucide-vue-next'

const { fetchSettings, saveSettings, testService } = useAdmin()
const { extractError } = useAuth()
const toast = useToast()

const settings = ref<AdminSettings | null>(null)
const original = ref<AdminSettings | null>(null)
const loading = ref(true)
const saving = ref(false)
const testing = ref<Record<string, boolean>>({})
const tab = ref('ai')
const apiKeyMask = ref('')
const confirmMaintenance = ref(false)

/** Nhận settings từ API: tách API key đã mask ra placeholder, giữ input trống = "không đổi". */
function applyFetched(s: AdminSettings) {
  apiKeyMask.value = s.ai.api_key || ''
  s.ai.api_key = ''
  settings.value = s
  original.value = JSON.parse(JSON.stringify(s))
}

async function load() {
  loading.value = true
  try { applyFetched(await fetchSettings()) }
  catch (e) { toast.error(extractError(e) || 'Không tải được cấu hình') }
  finally { loading.value = false }
}

const GROUP_LABELS: Record<keyof AdminSettings, string> = {
  ai: 'AI', rate_limit: 'Giới hạn tần suất', notifications: 'Thông báo',
  mail: 'Email', oauth: 'Đăng nhập MXH', features: 'Tính năng',
}

const dirtyGroups = computed<(keyof AdminSettings)[]>(() => {
  if (!settings.value || !original.value) return []
  return (Object.keys(GROUP_LABELS) as (keyof AdminSettings)[])
    .filter(g => JSON.stringify(settings.value![g]) !== JSON.stringify(original.value![g]))
})

const enablingMaintenance = computed(() =>
  !!settings.value?.features.maintenance_mode && !original.value?.features.maintenance_mode)

function resetChanges() {
  if (!original.value) return
  settings.value = JSON.parse(JSON.stringify(original.value))
}

async function saveAll(skipConfirm = false) {
  if (!settings.value || !dirtyGroups.value.length) return
  if (!skipConfirm && enablingMaintenance.value) {
    confirmMaintenance.value = true
    return
  }
  saving.value = true
  try {
    const payload: Partial<AdminSettings> = {}
    for (const g of dirtyGroups.value) (payload as Record<string, unknown>)[g] = settings.value[g]
    applyFetched(await saveSettings(payload))
    toast.success('Đã lưu cấu hình')
  } catch (e) {
    toast.error(extractError(e))
  } finally {
    saving.value = false
    confirmMaintenance.value = false
  }
}

async function runTest(svc: 'ai' | 'fcm' | 'mail') {
  testing.value[svc] = true
  try {
    const res = await testService(svc)
    const suffix = res.latency_ms != null ? ` (${res.latency_ms}ms)` : ''
    res.ok ? toast.success(res.message + suffix) : toast.error(res.message)
  } catch (e) {
    toast.error(extractError(e))
  } finally {
    testing.value[svc] = false
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-5xl">
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
      <div>
        <h1 class="text-xl font-bold">Cấu hình hệ thống</h1>
        <p class="text-sm text-muted-foreground mt-0.5">
          Thay đổi có hiệu lực ngay, không cần deploy lại.
        </p>
      </div>
      <Badge v-if="settings?.features.maintenance_mode" variant="destructive" class="gap-1">
        <AlertTriangle class="w-3 h-3" /> Đang bảo trì
      </Badge>
    </div>

    <div v-if="loading" class="space-y-4">
      <Skeleton class="h-9 w-96 rounded-lg" />
      <Skeleton v-for="i in 2" :key="i" class="h-64 rounded-xl" />
    </div>

    <Tabs v-else-if="settings" v-model="tab">
      <TabsList class="w-full sm:w-auto grid grid-cols-4 sm:inline-flex mb-2">
        <TabsTrigger value="ai"><Bot /> <span class="hidden sm:inline">AI &amp; Giới hạn</span><span class="sm:hidden">AI</span></TabsTrigger>
        <TabsTrigger value="notify"><Bell /> <span class="hidden sm:inline">Thông báo &amp; Email</span><span class="sm:hidden">Báo</span></TabsTrigger>
        <TabsTrigger value="access"><KeyRound /> <span class="hidden sm:inline">Đăng nhập &amp; Tính năng</span><span class="sm:hidden">Truy cập</span></TabsTrigger>
        <TabsTrigger value="system"><Server /> <span class="hidden sm:inline">Hệ thống</span><span class="sm:hidden">Hệ</span></TabsTrigger>
      </TabsList>

      <!-- ══ Tab: AI & Giới hạn ══ -->
      <TabsContent value="ai" class="space-y-4">
        <Card class="gap-4">
          <CardHeader class="flex-row items-start justify-between">
            <div class="space-y-1">
              <CardTitle class="text-base flex items-center gap-2"><Bot class="w-4 h-4 text-primary" /> AI (Gemini)</CardTitle>
              <CardDescription>Model, API key và tham số sinh nội dung cho toàn bộ tính năng AI.</CardDescription>
            </div>
            <Button variant="outline" size="sm" :disabled="testing.ai" @click="runTest('ai')">
              <Loader2 v-if="testing.ai" class="w-3.5 h-3.5 animate-spin" />
              <PlugZap v-else class="w-3.5 h-3.5" />
              {{ testing.ai ? 'Đang test…' : 'Test kết nối' }}
            </Button>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="space-y-1.5">
                <Label>Model</Label>
                <Input v-model="settings.ai.model" placeholder="gemini-2.0-flash" />
              </div>
              <div class="space-y-1.5">
                <Label>API Key</Label>
                <Input
                  v-model="settings.ai.api_key" class="font-mono"
                  :placeholder="apiKeyMask || 'Chưa cấu hình'" autocomplete="off"
                />
                <p class="text-[11px] text-muted-foreground">Để trống để giữ key hiện tại{{ apiKeyMask ? ` (${apiKeyMask})` : '' }}.</p>
              </div>
              <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                  <Label>Temperature</Label>
                  <span class="text-xs font-mono text-muted-foreground">{{ settings.ai.temperature }}</span>
                </div>
                <input v-model.number="settings.ai.temperature" type="range" min="0" max="2" step="0.1" class="mt-2 w-full accent-primary" />
                <p class="text-[11px] text-muted-foreground">Áp dụng cho AI chat — thấp: bám sát dữ kiện, cao: sáng tạo hơn.</p>
              </div>
              <div class="space-y-1.5">
                <Label>Max tokens</Label>
                <Input v-model.number="settings.ai.max_tokens" type="number" min="256" max="8192" />
                <p class="text-[11px] text-muted-foreground">Độ dài tối đa mỗi câu trả lời chat (256–8192).</p>
              </div>
            </div>
            <div class="divide-y rounded-lg border px-4">
              <div class="flex items-center justify-between gap-4 py-3">
                <div>
                  <p class="text-sm font-medium">Phân tích món ăn</p>
                  <p class="text-xs text-muted-foreground">Chụp/nhập món → AI ước tính dinh dưỡng. Tắt sẽ chặn scan &amp; nhận diện.</p>
                </div>
                <Switch v-model="settings.ai.food_analysis_enabled" />
              </div>
              <div class="flex items-center justify-between gap-4 py-3">
                <div>
                  <p class="text-sm font-medium">AI chat tư vấn</p>
                  <p class="text-xs text-muted-foreground">Trợ lý dinh dưỡng hội thoại. Tắt sẽ chặn chat &amp; thiết lập kế hoạch từ chat.</p>
                </div>
                <Switch v-model="settings.ai.chat_enabled" />
              </div>
            </div>
          </CardContent>
        </Card>

        <Card class="gap-4">
          <CardHeader>
            <CardTitle class="text-base flex items-center gap-2"><Gauge class="w-4 h-4 text-primary" /> Giới hạn tần suất</CardTitle>
            <CardDescription>Số request/phút cho mỗi người dùng (hoặc IP với khách) — áp dụng ngay khi lưu.</CardDescription>
          </CardHeader>
          <CardContent>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div class="space-y-1.5">
                <Label>Phân tích món</Label>
                <Input v-model.number="settings.rate_limit.food_analyze_per_min" type="number" min="1" max="120" />
              </div>
              <div class="space-y-1.5">
                <Label>Chat</Label>
                <Input v-model.number="settings.rate_limit.chat_per_min" type="number" min="1" max="120" />
              </div>
              <div class="space-y-1.5">
                <Label>Tạo kế hoạch</Label>
                <Input v-model.number="settings.rate_limit.plan_generate_per_min" type="number" min="1" max="60" />
              </div>
            </div>
          </CardContent>
        </Card>
      </TabsContent>

      <!-- ══ Tab: Thông báo & Email ══ -->
      <TabsContent value="notify" class="space-y-4">
        <Card class="gap-4">
          <CardHeader class="flex-row items-start justify-between">
            <div class="space-y-1">
              <CardTitle class="text-base flex items-center gap-2"><Bell class="w-4 h-4 text-primary" /> Push notification</CardTitle>
              <CardDescription>Giờ nhắc mặc định áp dụng cho tài khoản đăng ký mới.</CardDescription>
            </div>
            <Button variant="outline" size="sm" :disabled="testing.fcm" @click="runTest('fcm')">
              <Loader2 v-if="testing.fcm" class="w-3.5 h-3.5 animate-spin" />
              <PlugZap v-else class="w-3.5 h-3.5" />
              {{ testing.fcm ? 'Đang test…' : 'Test FCM' }}
            </Button>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="space-y-1.5">
                <Label>Nhắc buổi sáng (mặc định)</Label>
                <Input v-model="settings.notifications.morning_default" type="time" />
              </div>
              <div class="space-y-1.5">
                <Label>Nhắc buổi tối (mặc định)</Label>
                <Input v-model="settings.notifications.evening_default" type="time" />
              </div>
              <div class="space-y-1.5">
                <Label>Re-engagement sau (ngày)</Label>
                <Input v-model.number="settings.notifications.reengagement_days" type="number" min="1" max="30" />
                <p class="text-[11px] text-muted-foreground">Không hoạt động quá số ngày này → gửi email mời quay lại.</p>
              </div>
              <div class="space-y-1.5">
                <Label>FCM project</Label>
                <Input :model-value="settings.notifications.fcm_project_id || '—'" disabled />
                <p class="text-[11px] text-muted-foreground">Cấu hình qua biến môi trường Firebase.</p>
              </div>
            </div>
            <div class="rounded-lg border px-4">
              <div class="flex items-center justify-between gap-4 py-3">
                <div>
                  <p class="text-sm font-medium">Bật push notification</p>
                  <p class="text-xs text-muted-foreground">Tắt sẽ ngưng toàn bộ push (nhắc bữa, streak, chiến dịch); thông báo in-app vẫn lưu.</p>
                </div>
                <Switch v-model="settings.notifications.fcm_enabled" />
              </div>
            </div>
          </CardContent>
        </Card>

        <Card class="gap-4">
          <CardHeader class="flex-row items-start justify-between">
            <div class="space-y-1">
              <CardTitle class="text-base flex items-center gap-2"><Mail class="w-4 h-4 text-primary" /> Email</CardTitle>
              <CardDescription>Địa chỉ gửi áp dụng cho mọi email hệ thống (OTP, đặt lại mật khẩu, re-engagement).</CardDescription>
            </div>
            <Button variant="outline" size="sm" :disabled="testing.mail" @click="runTest('mail')">
              <Loader2 v-if="testing.mail" class="w-3.5 h-3.5 animate-spin" />
              <PlugZap v-else class="w-3.5 h-3.5" />
              {{ testing.mail ? 'Đang gửi…' : 'Gửi mail test' }}
            </Button>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="space-y-1.5">
                <Label>From address</Label>
                <Input v-model="settings.mail.from_address" type="email" placeholder="noreply@caloeye.app" />
              </div>
              <div class="space-y-1.5">
                <Label>From name</Label>
                <Input v-model="settings.mail.from_name" placeholder="CaloEye" />
              </div>
            </div>
            <div class="rounded-lg border px-4">
              <div class="flex items-center justify-between gap-4 py-3">
                <div>
                  <p class="text-sm font-medium">Email re-engagement</p>
                  <p class="text-xs text-muted-foreground">Công tắc tổng — tắt sẽ ngưng gửi cho tất cả, kể cả user đã bật trong app.</p>
                </div>
                <Switch v-model="settings.mail.reengagement_enabled" />
              </div>
            </div>
          </CardContent>
        </Card>
      </TabsContent>

      <!-- ══ Tab: Đăng nhập & Tính năng ══ -->
      <TabsContent value="access" class="space-y-4">
        <Card class="gap-4">
          <CardHeader>
            <CardTitle class="text-base flex items-center gap-2"><KeyRound class="w-4 h-4 text-primary" /> Đăng nhập mạng xã hội</CardTitle>
            <CardDescription>Tắt sẽ ẩn nút trên trang đăng nhập và chặn luồng OAuth phía server.</CardDescription>
          </CardHeader>
          <CardContent>
            <div class="divide-y rounded-lg border px-4">
              <div class="flex items-center justify-between gap-4 py-3">
                <div>
                  <p class="text-sm font-medium">Đăng nhập Google</p>
                  <p class="text-xs text-muted-foreground">OAuth qua tài khoản Google.</p>
                </div>
                <Switch v-model="settings.oauth.google_enabled" />
              </div>
              <div class="flex items-center justify-between gap-4 py-3">
                <div>
                  <p class="text-sm font-medium">Đăng nhập Facebook</p>
                  <p class="text-xs text-muted-foreground">OAuth qua tài khoản Facebook.</p>
                </div>
                <Switch v-model="settings.oauth.facebook_enabled" />
              </div>
            </div>
          </CardContent>
        </Card>

        <Card class="gap-4">
          <CardHeader>
            <CardTitle class="text-base flex items-center gap-2"><SlidersHorizontal class="w-4 h-4 text-primary" /> Tính năng</CardTitle>
            <CardDescription>Bật/tắt các luồng chính của ứng dụng.</CardDescription>
          </CardHeader>
          <CardContent>
            <div class="divide-y rounded-lg border px-4">
              <div class="flex items-center justify-between gap-4 py-3">
                <div>
                  <p class="text-sm font-medium">Cho phép đăng ký mới</p>
                  <p class="text-xs text-muted-foreground">Tắt sẽ chặn tạo tài khoản; user hiện có vẫn đăng nhập bình thường.</p>
                </div>
                <Switch v-model="settings.features.registration_open" />
              </div>
              <div class="flex items-center justify-between gap-4 py-3">
                <div>
                  <p class="text-sm font-medium">Chế độ khách</p>
                  <p class="text-xs text-muted-foreground">Cho phép dùng thử không cần tài khoản (quota giới hạn).</p>
                </div>
                <Switch v-model="settings.features.guest_mode_enabled" />
              </div>
              <div class="flex items-center justify-between gap-4 py-3">
                <div>
                  <p class="text-sm font-medium text-destructive flex items-center gap-1.5">
                    <AlertTriangle class="w-3.5 h-3.5" /> Chế độ bảo trì
                  </p>
                  <p class="text-xs text-muted-foreground">Tạm ngưng toàn bộ API với người dùng thường — admin vẫn truy cập được.</p>
                </div>
                <Switch v-model="settings.features.maintenance_mode" class="data-[state=checked]:bg-destructive" />
              </div>
            </div>
          </CardContent>
        </Card>
      </TabsContent>

      <!-- ══ Tab: Hệ thống ══ -->
      <TabsContent value="system">
        <SystemPanel />
      </TabsContent>

      <!-- Thanh lưu thay đổi (hiện khi có chỉnh sửa) -->
      <div
        v-if="dirtyGroups.length"
        class="sticky bottom-4 z-10 mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-background/95 backdrop-blur px-4 py-3 shadow-lg"
      >
        <p class="text-sm text-muted-foreground">
          Chưa lưu:
          <span class="font-medium text-foreground">{{ dirtyGroups.map(g => GROUP_LABELS[g]).join(', ') }}</span>
        </p>
        <div class="flex gap-2">
          <Button variant="outline" size="sm" :disabled="saving" @click="resetChanges">
            <RotateCcw class="w-3.5 h-3.5" /> Hoàn tác
          </Button>
          <Button size="sm" :disabled="saving" @click="saveAll()">
            <Loader2 v-if="saving" class="w-3.5 h-3.5 animate-spin" />
            <Save v-else class="w-3.5 h-3.5" />
            Lưu thay đổi
          </Button>
        </div>
      </div>
    </Tabs>

    <!-- Xác nhận bật bảo trì -->
    <AlertDialog v-model:open="confirmMaintenance">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle class="flex items-center gap-2">
            <AlertTriangle class="w-5 h-5 text-destructive" /> Bật chế độ bảo trì?
          </AlertDialogTitle>
          <AlertDialogDescription>
            Toàn bộ người dùng thường sẽ không truy cập được ứng dụng cho tới khi bạn tắt lại.
            Tài khoản admin không bị ảnh hưởng.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Huỷ</AlertDialogCancel>
          <AlertDialogAction class="bg-destructive text-white hover:bg-destructive/90" @click="saveAll(true)">
            Bật bảo trì
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  </div>
</template>
