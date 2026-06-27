<script setup lang="ts">
import { ref, reactive, watch, onMounted } from 'vue'
import { useAdmin } from '@/composables/useAdmin'
import { useAuth } from '@/composables/useAuth'
import { useToast } from '@/composables/useToast'
import type { NotificationSegment, NotificationCampaign } from '@/types/admin'

const { previewNotification, sendNotification, fetchCampaigns } = useAdmin()
const { extractError } = useAuth()
const toast = useToast()

const form = reactive({ title: '', body: '', url: '/home' })
const segment = reactive<NotificationSegment>({
  audience: 'all', role: '', provider: '', gender: '', activity: '',
  has_streak: false, only_subscribed: false,
})

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
    <h1 class="text-xl font-bold text-gray-900 mb-4">Gửi thông báo</h1>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <!-- Compose -->
      <section class="bg-white rounded-xl border border-gray-200 p-5">
        <h2 class="font-semibold text-gray-800 mb-4">Nội dung</h2>
        <label class="block mb-3">
          <span class="text-xs text-gray-500">Tiêu đề</span>
          <input v-model="form.title" maxlength="120" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg"
                 placeholder="VD: Cập nhật mới 🎉" />
        </label>
        <label class="block mb-3">
          <span class="text-xs text-gray-500">Nội dung</span>
          <textarea v-model="form.body" maxlength="500" rows="3"
                    class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg resize-none"
                    placeholder="Nội dung thông báo…"></textarea>
          <span class="text-[11px] text-gray-400">{{ form.body.length }}/500</span>
        </label>
        <label class="block">
          <span class="text-xs text-gray-500">Mở màn hình khi chạm (tuỳ chọn)</span>
          <input v-model="form.url" class="mt-1 w-full px-3 py-2 text-sm border border-gray-200 rounded-lg font-mono"
                 placeholder="/home" />
        </label>

        <!-- Live preview card -->
        <div class="mt-4 p-3 rounded-lg bg-gray-50 border border-gray-100">
          <div class="text-[11px] text-gray-400 mb-1">Xem trước</div>
          <div class="text-sm font-semibold text-gray-800">{{ form.title || 'Tiêu đề thông báo' }}</div>
          <div class="text-sm text-gray-600">{{ form.body || 'Nội dung thông báo sẽ hiển thị ở đây' }}</div>
        </div>
      </section>

      <!-- Segment -->
      <section class="bg-white rounded-xl border border-gray-200 p-5">
        <h2 class="font-semibold text-gray-800 mb-4">Đối tượng nhận</h2>

        <div class="inline-flex bg-gray-100 rounded-lg p-0.5 mb-4">
          <button class="px-4 py-1.5 text-sm font-medium rounded-md transition-colors"
                  :class="segment.audience === 'all' ? 'bg-calor-green text-white' : 'text-gray-600'"
                  @click="segment.audience = 'all'">Tất cả</button>
          <button class="px-4 py-1.5 text-sm font-medium rounded-md transition-colors"
                  :class="segment.audience === 'segment' ? 'bg-calor-green text-white' : 'text-gray-600'"
                  @click="segment.audience = 'segment'">Theo phân khúc</button>
        </div>

        <div v-if="segment.audience === 'segment'" class="space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <label class="block">
              <span class="text-xs text-gray-500">Vai trò</span>
              <select v-model="segment.role" class="mt-1 w-full px-2 py-2 text-sm border border-gray-200 rounded-lg">
                <option value="">Bất kỳ</option><option value="user">User</option><option value="admin">Admin</option>
              </select>
            </label>
            <label class="block">
              <span class="text-xs text-gray-500">Nguồn đăng nhập</span>
              <select v-model="segment.provider" class="mt-1 w-full px-2 py-2 text-sm border border-gray-200 rounded-lg">
                <option value="">Bất kỳ</option><option value="email">Email</option><option value="google">Google</option><option value="facebook">Facebook</option>
              </select>
            </label>
            <label class="block">
              <span class="text-xs text-gray-500">Giới tính</span>
              <select v-model="segment.gender" class="mt-1 w-full px-2 py-2 text-sm border border-gray-200 rounded-lg">
                <option value="">Bất kỳ</option><option value="male">Nam</option><option value="female">Nữ</option><option value="other">Khác</option>
              </select>
            </label>
            <label class="block">
              <span class="text-xs text-gray-500">Hoạt động</span>
              <select v-model="segment.activity" class="mt-1 w-full px-2 py-2 text-sm border border-gray-200 rounded-lg">
                <option value="">Bất kỳ</option>
                <option value="active_7d">Active 7 ngày</option>
                <option value="inactive_7d">Không hoạt động 7 ngày</option>
                <option value="inactive_30d">Không hoạt động 30 ngày</option>
              </select>
            </label>
          </div>
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="segment.has_streak" /> Đang có chuỗi streak</label>
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="segment.only_subscribed" /> Chỉ người đã bật push</label>
        </div>

        <!-- Audience count -->
        <div class="mt-4 p-4 rounded-lg bg-calor-light/60 border border-calor-mint/40">
          <div class="text-xs text-calor-deep/70">Số người nhận ước tính</div>
          <div class="text-2xl font-bold text-calor-deep">
            <span v-if="previewing" class="text-base text-gray-400">Đang tính…</span>
            <span v-else>{{ preview?.audience_count ?? 0 }}</span>
          </div>
          <div class="text-xs text-calor-deep/60" v-if="preview">
            trong đó {{ preview.subscribed_count }} người có thiết bị nhận push
          </div>
        </div>

        <button class="mt-4 w-full py-2.5 bg-calor-green text-white text-sm font-semibold rounded-lg hover:bg-calor-dark disabled:opacity-60"
                :disabled="sending" @click="submit">
          {{ sending ? 'Đang gửi…' : 'Gửi thông báo' }}
        </button>
      </section>
    </div>

    <!-- History -->
    <section class="bg-white rounded-xl border border-gray-200 mt-4 overflow-hidden">
      <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100">
        <h2 class="font-semibold text-gray-800">Lịch sử chiến dịch</h2>
        <button class="text-sm text-calor-green hover:underline" @click="loadHistory">Làm mới</button>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
              <th class="text-left font-medium px-4 py-3">Tiêu đề</th>
              <th class="text-left font-medium px-4 py-3">Trạng thái</th>
              <th class="text-right font-medium px-4 py-3">Mục tiêu</th>
              <th class="text-right font-medium px-4 py-3">Đã gửi</th>
              <th class="text-right font-medium px-4 py-3">Push</th>
              <th class="text-left font-medium px-4 py-3">Thời gian</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loadingHistory"><td colspan="6" class="px-4 py-8 text-center text-gray-400">Đang tải…</td></tr>
            <tr v-else-if="!campaigns.length"><td colspan="6" class="px-4 py-8 text-center text-gray-400">Chưa có chiến dịch nào</td></tr>
            <tr v-for="c in campaigns" :key="c.id" class="hover:bg-gray-50">
              <td class="px-4 py-3">
                <div class="font-medium text-gray-800">{{ c.title }}</div>
                <div class="text-xs text-gray-400 truncate max-w-[260px]">{{ c.body }}</div>
              </td>
              <td class="px-4 py-3">
                <span class="text-xs px-2 py-0.5 rounded-full" :class="STATUS_LABEL[c.status]?.cls">{{ STATUS_LABEL[c.status]?.text || c.status }}</span>
              </td>
              <td class="px-4 py-3 text-right text-gray-600">{{ c.audience_count }}</td>
              <td class="px-4 py-3 text-right text-gray-600">{{ c.sent_count }}</td>
              <td class="px-4 py-3 text-right text-gray-600">{{ c.push_count }}</td>
              <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ fmt(c.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
