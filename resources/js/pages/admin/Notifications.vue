<script setup lang="ts">
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useAdmin } from '@/composables/useAdmin'
import { useAuth } from '@/composables/useAuth'
import { useToast } from '@/composables/useToast'
import type { NotificationSegment, NotificationCampaign } from '@/types/admin'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table'
import { Loader2, RefreshCw } from 'lucide-vue-next'

const { previewNotification, sendNotification, fetchCampaigns } = useAdmin()
const { extractError } = useAuth()
const toast = useToast()

const form = reactive({ title: '', body: '', url: '/home' })
const segment = reactive<NotificationSegment>({
  audience: 'all', role: '', provider: '', gender: '', activity: '',
  has_streak: false, only_subscribed: false,
})

// shadcn Select không nhận value rỗng → sentinel 'any' map về ''
function segmentModel(key: 'role' | 'provider' | 'gender' | 'activity') {
  return computed({
    get: () => (segment[key] as string) || 'any',
    set: (v: string) => { (segment[key] as string) = v === 'any' ? '' : v },
  })
}
const roleModel = segmentModel('role')
const providerModel = segmentModel('provider')
const genderModel = segmentModel('gender')
const activityModel = segmentModel('activity')

const preview = ref<{ audience_count: number; subscribed_count: number } | null>(null)
const previewing = ref(false)
const sending = ref(false)

const campaigns = ref<NotificationCampaign[]>([])
const loadingHistory = ref(true)

let previewTimer: ReturnType<typeof setTimeout> | undefined
async function runPreview() {
  previewing.value = true
  try {
    preview.value = await previewNotification({ ...segment })
  } catch {
    preview.value = null
  } finally {
    previewing.value = false
  }
}

watch(segment, () => {
  clearTimeout(previewTimer)
  previewTimer = setTimeout(runPreview, 300)
}, { deep: true })

async function loadHistory() {
  loadingHistory.value = true
  try {
    const res = await fetchCampaigns()
    campaigns.value = res.data
  } catch (e) {
    toast.error(extractError(e))
  } finally {
    loadingHistory.value = false
  }
}

async function submit() {
  if (!form.title.trim() || !form.body.trim()) {
    toast.error('Vui lòng nhập tiêu đề và nội dung')
    return
  }
  const count = preview.value?.audience_count ?? 0
  if (!confirm(`Gửi thông báo này tới ${count} người dùng?`)) return

  sending.value = true
  try {
    await sendNotification({ title: form.title, body: form.body, url: form.url || undefined, segment: { ...segment } })
    toast.success(`Đã tạo chiến dịch, đang gửi tới ${count} người dùng`)
    form.title = ''; form.body = ''
    loadHistory()
  } catch (e) {
    toast.error(extractError(e))
  } finally {
    sending.value = false
  }
}

const STATUS_LABEL: Record<string, { text: string; cls: string }> = {
  queued:  { text: 'Đang chờ',   cls: 'bg-gray-100 text-gray-600' },
  sending: { text: 'Đang gửi',   cls: 'bg-blue-100 text-blue-700' },
  done:    { text: 'Hoàn thành', cls: 'bg-green-100 text-green-700' },
  failed:  { text: 'Lỗi',        cls: 'bg-red-100 text-red-700' },
}

function fmt(s: string): string {
  return new Date(s).toLocaleString('vi-VN')
}

onMounted(() => { runPreview(); loadHistory() })
</script>

<template>
  <div class="max-w-4xl">
    <h1 class="text-xl font-bold mb-4">Gửi thông báo</h1>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <!-- Compose -->
      <Card class="gap-4">
        <CardHeader><CardTitle class="text-base">Nội dung</CardTitle></CardHeader>
        <CardContent class="space-y-3">
          <div class="space-y-1.5">
            <Label>Tiêu đề</Label>
            <Input v-model="form.title" maxlength="120" placeholder="VD: Cập nhật mới 🎉" />
          </div>
          <div class="space-y-1.5">
            <Label>Nội dung</Label>
            <Textarea v-model="form.body" maxlength="500" rows="3" class="resize-none" placeholder="Nội dung thông báo…" />
            <span class="text-[11px] text-muted-foreground">{{ form.body.length }}/500</span>
          </div>
          <div class="space-y-1.5">
            <Label>Mở màn hình khi chạm (tuỳ chọn)</Label>
            <Input v-model="form.url" class="font-mono" placeholder="/home" />
          </div>

          <!-- Live preview card -->
          <div class="mt-1 p-3 rounded-lg bg-muted border">
            <div class="text-[11px] text-muted-foreground mb-1">Xem trước</div>
            <div class="text-sm font-semibold">{{ form.title || 'Tiêu đề thông báo' }}</div>
            <div class="text-sm text-muted-foreground">{{ form.body || 'Nội dung thông báo sẽ hiển thị ở đây' }}</div>
          </div>
        </CardContent>
      </Card>

      <!-- Segment -->
      <Card class="gap-4">
        <CardHeader><CardTitle class="text-base">Đối tượng nhận</CardTitle></CardHeader>
        <CardContent>
          <div class="inline-flex bg-muted rounded-lg p-0.5 mb-4 gap-0.5">
            <Button size="sm" :variant="segment.audience === 'all' ? 'default' : 'ghost'" @click="segment.audience = 'all'">Tất cả</Button>
            <Button size="sm" :variant="segment.audience === 'segment' ? 'default' : 'ghost'" @click="segment.audience = 'segment'">Theo phân khúc</Button>
          </div>

          <div v-if="segment.audience === 'segment'" class="space-y-3">
            <div class="grid grid-cols-2 gap-3">
              <div class="space-y-1.5">
                <Label>Vai trò</Label>
                <Select v-model="roleModel">
                  <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="any">Bất kỳ</SelectItem>
                    <SelectItem value="user">User</SelectItem>
                    <SelectItem value="admin">Admin</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div class="space-y-1.5">
                <Label>Nguồn đăng nhập</Label>
                <Select v-model="providerModel">
                  <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="any">Bất kỳ</SelectItem>
                    <SelectItem value="email">Email</SelectItem>
                    <SelectItem value="google">Google</SelectItem>
                    <SelectItem value="facebook">Facebook</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div class="space-y-1.5">
                <Label>Giới tính</Label>
                <Select v-model="genderModel">
                  <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="any">Bất kỳ</SelectItem>
                    <SelectItem value="male">Nam</SelectItem>
                    <SelectItem value="female">Nữ</SelectItem>
                    <SelectItem value="other">Khác</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div class="space-y-1.5">
                <Label>Hoạt động</Label>
                <Select v-model="activityModel">
                  <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="any">Bất kỳ</SelectItem>
                    <SelectItem value="active_7d">Active 7 ngày</SelectItem>
                    <SelectItem value="inactive_7d">Không hoạt động 7 ngày</SelectItem>
                    <SelectItem value="inactive_30d">Không hoạt động 30 ngày</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
            <label class="flex items-center gap-2 text-sm">
              <Switch v-model="segment.has_streak" /> Đang có chuỗi streak
            </label>
            <label class="flex items-center gap-2 text-sm">
              <Switch v-model="segment.only_subscribed" /> Chỉ người đã bật push
            </label>
          </div>

          <!-- Audience count -->
          <div class="mt-4 p-4 rounded-lg bg-calor-light/60 border border-calor-mint/40">
            <div class="text-xs text-calor-deep/70">Số người nhận ước tính</div>
            <div class="text-2xl font-bold text-calor-deep">
              <span v-if="previewing" class="text-base text-muted-foreground">Đang tính…</span>
              <span v-else>{{ preview?.audience_count ?? 0 }}</span>
            </div>
            <div v-if="preview" class="text-xs text-calor-deep/60">
              trong đó {{ preview.subscribed_count }} người có thiết bị nhận push
            </div>
          </div>

          <Button class="mt-4 w-full" :disabled="sending" @click="submit">
            <Loader2 v-if="sending" class="w-4 h-4 animate-spin" />
            {{ sending ? 'Đang gửi…' : 'Gửi thông báo' }}
          </Button>
        </CardContent>
      </Card>
    </div>

    <!-- History -->
    <Card class="mt-4 py-0 gap-0 overflow-hidden">
      <div class="flex items-center justify-between px-5 py-3 border-b">
        <h2 class="font-semibold">Lịch sử chiến dịch</h2>
        <Button variant="ghost" size="sm" @click="loadHistory">
          <RefreshCw class="w-3.5 h-3.5" /> Làm mới
        </Button>
      </div>
      <div class="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Tiêu đề</TableHead>
              <TableHead>Trạng thái</TableHead>
              <TableHead class="text-right">Mục tiêu</TableHead>
              <TableHead class="text-right">Đã gửi</TableHead>
              <TableHead class="text-right">Push</TableHead>
              <TableHead>Thời gian</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <template v-if="loadingHistory">
              <TableRow v-for="i in 3" :key="i">
                <TableCell v-for="j in 6" :key="j"><Skeleton class="h-4 w-full" /></TableCell>
              </TableRow>
            </template>
            <TableRow v-else-if="!campaigns.length">
              <TableCell colspan="6" class="py-8 text-center text-muted-foreground">Chưa có chiến dịch nào</TableCell>
            </TableRow>
            <TableRow v-for="c in campaigns" v-else :key="c.id">
              <TableCell>
                <div class="font-medium">{{ c.title }}</div>
                <div class="text-xs text-muted-foreground truncate max-w-[260px]">{{ c.body }}</div>
              </TableCell>
              <TableCell>
                <Badge variant="secondary" :class="STATUS_LABEL[c.status]?.cls">{{ STATUS_LABEL[c.status]?.text || c.status }}</Badge>
              </TableCell>
              <TableCell class="text-right text-muted-foreground tabular-nums">{{ c.audience_count }}</TableCell>
              <TableCell class="text-right text-muted-foreground tabular-nums">{{ c.sent_count }}</TableCell>
              <TableCell class="text-right text-muted-foreground tabular-nums">{{ c.push_count }}</TableCell>
              <TableCell class="text-muted-foreground whitespace-nowrap">{{ fmt(c.created_at) }}</TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>
    </Card>
  </div>
</template>
