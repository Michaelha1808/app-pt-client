<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useAdmin } from '@/composables/useAdmin'
import { useAuth } from '@/composables/useAuth'
import { useToast } from '@/composables/useToast'
import type { AuditLogRow } from '@/types/admin'

const { fetchAuditLogs } = useAdmin()
const { extractError } = useAuth()
const toast = useToast()

const rows = ref<AuditLogRow[]>([])
const loading = ref(true)
const meta = ref({ current_page: 1, per_page: 30, total: 0, last_page: 1 })
const actionFilter = ref('')

const ACTION_LABELS: Record<string, string> = {
  'user.update': 'Sửa người dùng',
  'user.suspend': 'Khoá tài khoản',
  'user.restore': 'Mở khoá / khôi phục',
  'user.reset_password': 'Reset mật khẩu',
  'user.delete': 'Xoá tài khoản',
  'settings.update': 'Cập nhật cấu hình',
}

async function load(page = 1) {
  loading.value = true
  try {
    const res = await fetchAuditLogs({ page, action: actionFilter.value || undefined })
    rows.value = res.data
    meta.value = res.meta
  } catch (e) {
    toast.error(extractError(e) || 'Không tải được nhật ký')
  } finally {
    loading.value = false
  }
}

function fmt(s: string): string {
  return new Date(s).toLocaleString('vi-VN')
}

onMounted(() => load())
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <h1 class="text-xl font-bold text-gray-900">Nhật ký quản trị</h1>
      <select v-model="actionFilter" @change="load(1)" class="px-2 py-2 text-sm border border-gray-200 rounded-lg bg-white">
        <option value="">Tất cả hành động</option>
        <option v-for="(label, key) in ACTION_LABELS" :key="key" :value="key">{{ label }}</option>
      </select>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
              <th class="text-left font-medium px-4 py-3">Thời gian</th>
              <th class="text-left font-medium px-4 py-3">Admin</th>
              <th class="text-left font-medium px-4 py-3">Hành động</th>
              <th class="text-left font-medium px-4 py-3">Đối tượng</th>
              <th class="text-left font-medium px-4 py-3">IP</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="5" class="px-4 py-10 text-center text-gray-400">Đang tải…</td></tr>
            <tr v-else-if="!rows.length"><td colspan="5" class="px-4 py-10 text-center text-gray-400">Chưa có nhật ký</td></tr>
            <tr v-for="l in rows" :key="l.id" class="hover:bg-gray-50">
              <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ fmt(l.created_at) }}</td>
              <td class="px-4 py-3">
                <div class="text-gray-800">{{ l.admin?.name || '—' }}</div>
                <div class="text-xs text-gray-400">{{ l.admin?.email }}</div>
              </td>
              <td class="px-4 py-3">
                <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-700">{{ ACTION_LABELS[l.action] || l.action }}</span>
              </td>
              <td class="px-4 py-3 text-gray-500">
                <span v-if="l.target_type">{{ l.target_type }}#{{ l.target_id }}</span>
                <span v-else>—</span>
              </td>
              <td class="px-4 py-3 text-gray-400 font-mono text-xs">{{ l.ip || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="flex items-center justify-between px-4 py-3 border-t border-gray-100 text-sm text-gray-500">
        <span>Tổng {{ meta.total }} bản ghi</span>
        <div class="flex items-center gap-1">
          <button class="px-2 py-1 rounded hover:bg-gray-100 disabled:opacity-40" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">‹</button>
          <span class="px-2">Trang {{ meta.current_page }} / {{ meta.last_page }}</span>
          <button class="px-2 py-1 rounded hover:bg-gray-100 disabled:opacity-40" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">›</button>
        </div>
      </div>
    </div>
  </div>
</template>
