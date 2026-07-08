<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useAdmin } from '@/composables/useAdmin'
import { useAuth } from '@/composables/useAuth'
import { useToast } from '@/composables/useToast'
import type { AuditLogRow } from '@/types/admin'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table'
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'

const { fetchAuditLogs } = useAdmin()
const { extractError } = useAuth()
const toast = useToast()

const rows = ref<AuditLogRow[]>([])
const loading = ref(true)
const meta = ref({ current_page: 1, per_page: 30, total: 0, last_page: 1 })
const actionFilter = ref('')

// shadcn Select không nhận value rỗng → sentinel 'all'
const actionModel = computed({
  get: () => actionFilter.value || 'all',
  set: (v: string) => { actionFilter.value = v === 'all' ? '' : v; load(1) },
})

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
      <h1 class="text-xl font-bold">Nhật ký quản trị</h1>
      <Select v-model="actionModel">
        <SelectTrigger class="w-52"><SelectValue /></SelectTrigger>
        <SelectContent>
          <SelectItem value="all">Tất cả hành động</SelectItem>
          <SelectItem v-for="(label, key) in ACTION_LABELS" :key="key" :value="key">{{ label }}</SelectItem>
        </SelectContent>
      </Select>
    </div>

    <Card class="py-0 gap-0 overflow-hidden">
      <div class="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Thời gian</TableHead>
              <TableHead>Admin</TableHead>
              <TableHead>Hành động</TableHead>
              <TableHead>Đối tượng</TableHead>
              <TableHead>IP</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <template v-if="loading">
              <TableRow v-for="i in 5" :key="i">
                <TableCell v-for="j in 5" :key="j"><Skeleton class="h-4 w-full" /></TableCell>
              </TableRow>
            </template>
            <TableRow v-else-if="!rows.length">
              <TableCell colspan="5" class="py-10 text-center text-muted-foreground">Chưa có nhật ký</TableCell>
            </TableRow>
            <TableRow v-for="l in rows" v-else :key="l.id">
              <TableCell class="text-muted-foreground whitespace-nowrap">{{ fmt(l.created_at) }}</TableCell>
              <TableCell>
                <div>{{ l.admin?.name || '—' }}</div>
                <div class="text-xs text-muted-foreground">{{ l.admin?.email }}</div>
              </TableCell>
              <TableCell>
                <Badge variant="secondary">{{ ACTION_LABELS[l.action] || l.action }}</Badge>
              </TableCell>
              <TableCell class="text-muted-foreground">
                <span v-if="l.target_type">{{ l.target_type }}#{{ l.target_id }}</span>
                <span v-else>—</span>
              </TableCell>
              <TableCell class="text-muted-foreground font-mono text-xs">{{ l.ip || '—' }}</TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>
      <div class="flex items-center justify-between px-4 py-3 border-t text-sm text-muted-foreground">
        <span>Tổng {{ meta.total }} bản ghi</span>
        <div class="flex items-center gap-1">
          <Button variant="ghost" size="icon" class="h-8 w-8" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">
            <ChevronLeft class="w-4 h-4" />
          </Button>
          <span class="px-2">Trang {{ meta.current_page }} / {{ meta.last_page }}</span>
          <Button variant="ghost" size="icon" class="h-8 w-8" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">
            <ChevronRight class="w-4 h-4" />
          </Button>
        </div>
      </div>
    </Card>
  </div>
</template>
