<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useAdmin } from '@/composables/useAdmin'
import { useAuth } from '@/composables/useAuth'
import { useToast } from '@/composables/useToast'
import type { ChatLogRow, ChatLogDetail } from '@/types/admin'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table'
import EmptyState from '@/components/admin/EmptyState.vue'
import IconAction from '@/components/admin/IconAction.vue'
import { ChevronLeft, ChevronRight, Eye, MessageCircle, Loader2, Search } from 'lucide-vue-next'

const { fetchChatLogs, fetchChatLog } = useAdmin()
const { extractError } = useAuth()
const toast = useToast()

const rows = ref<ChatLogRow[]>([])
const loading = ref(true)
const meta = ref({ current_page: 1, per_page: 30, total: 0, last_page: 1 })
const search = ref('')
const scopeFilter = ref('')

// shadcn Select không nhận value rỗng → sentinel 'all'
const scopeModel = computed({
  get: () => scopeFilter.value || 'all',
  set: (v: string) => { scopeFilter.value = v === 'all' ? '' : v; load(1) },
})

const detail = ref<ChatLogDetail | null>(null)
const detailLoading = ref(false)

const dialogOpen = computed({
  get: () => detailLoading.value || !!detail.value,
  set: (v: boolean) => { if (!v) detail.value = null },
})

let searchTimer: ReturnType<typeof setTimeout> | undefined

function onSearchInput() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => load(1), 350)
}

async function load(page = 1) {
  loading.value = true
  try {
    const res = await fetchChatLogs({
      page,
      search: search.value || undefined,
      in_scope: scopeFilter.value || undefined,
    })
    rows.value = res.data
    meta.value = res.meta
  } catch (e) {
    toast.error(extractError(e) || 'Không tải được nhật ký chat')
  } finally {
    loading.value = false
  }
}

async function openDetail(id: number) {
  detailLoading.value = true
  detail.value = null
  try {
    detail.value = await fetchChatLog(id)
  } catch (e) {
    toast.error(extractError(e) || 'Không tải được chi tiết')
  } finally {
    detailLoading.value = false
  }
}

function fmt(s: string): string {
  return new Date(s).toLocaleString('vi-VN')
}

function truncate(s: string, n = 90): string {
  return s.length > n ? s.slice(0, n) + '…' : s
}

onMounted(() => load())
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-5 flex-wrap gap-2">
      <div>
        <h1 class="text-xl font-bold">Nhật ký prompt Chatbot AI</h1>
        <p class="text-sm text-muted-foreground mt-0.5">
          Câu hỏi của người dùng và prompt cá nhân hóa cuối cùng đã gửi Gemini — dùng để đối chiếu mức độ cá nhân hóa.
        </p>
      </div>
      <div class="flex items-center gap-2">
        <div class="relative">
          <Search class="w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input v-model="search" placeholder="Tìm theo câu hỏi…" class="pl-8 w-56" @input="onSearchInput" />
        </div>
        <Select v-model="scopeModel">
          <SelectTrigger class="w-48"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">Tất cả</SelectItem>
            <SelectItem value="true">Trong phạm vi</SelectItem>
            <SelectItem value="false">Ngoài phạm vi</SelectItem>
          </SelectContent>
        </Select>
      </div>
    </div>

    <Card class="py-0 gap-0 overflow-hidden shadow-xs">
      <div class="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow class="hover:bg-transparent">
              <TableHead>Thời gian</TableHead>
              <TableHead>Người dùng</TableHead>
              <TableHead>Câu hỏi</TableHead>
              <TableHead>Trạng thái</TableHead>
              <TableHead>Model</TableHead>
              <TableHead class="text-right">Thao tác</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <template v-if="loading">
              <TableRow v-for="i in 6" :key="i">
                <TableCell v-for="j in 6" :key="j"><Skeleton class="h-4 w-full" /></TableCell>
              </TableRow>
            </template>
            <TableRow v-else-if="!rows.length" class="hover:bg-transparent">
              <TableCell colspan="6" class="p-0">
                <EmptyState :icon="MessageCircle" title="Chưa có nhật ký chat" hint="Mỗi lượt hỏi chatbot tư vấn sẽ được ghi lại tại đây." />
              </TableCell>
            </TableRow>
            <TableRow v-for="r in rows" v-else :key="r.id" class="cursor-pointer" @click="openDetail(r.id)">
              <TableCell class="text-muted-foreground whitespace-nowrap">{{ fmt(r.created_at) }}</TableCell>
              <TableCell>
                <div>{{ r.user?.name || 'Khách' }}</div>
                <div class="text-xs text-muted-foreground">{{ r.user?.email }}</div>
              </TableCell>
              <TableCell class="max-w-96">
                <span class="block truncate" :title="r.user_message">{{ truncate(r.user_message) }}</span>
              </TableCell>
              <TableCell>
                <Badge v-if="r.in_scope" variant="secondary" class="bg-calor-green/10 text-calor-deep">Trong phạm vi</Badge>
                <Badge v-else variant="secondary" class="bg-amber-50 text-amber-600">Ngoài phạm vi</Badge>
              </TableCell>
              <TableCell class="text-muted-foreground text-xs font-mono">{{ r.model || '—' }}</TableCell>
              <TableCell class="text-right" @click.stop>
                <IconAction label="Xem prompt đã gửi" tone="view" @click="openDetail(r.id)"><Eye /></IconAction>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>
      <div class="flex items-center justify-between px-4 py-3 border-t text-sm text-muted-foreground">
        <span>Tổng {{ meta.total }} lượt hỏi</span>
        <div class="flex items-center gap-1">
          <Button variant="outline" size="icon" class="h-8 w-8" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">
            <ChevronLeft class="w-4 h-4" />
          </Button>
          <span class="px-2 tabular-nums">Trang {{ meta.current_page }} / {{ meta.last_page }}</span>
          <Button variant="outline" size="icon" class="h-8 w-8" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">
            <ChevronRight class="w-4 h-4" />
          </Button>
        </div>
      </div>
    </Card>

    <!-- Detail dialog -->
    <Dialog v-model:open="dialogOpen">
      <DialogContent class="max-w-3xl max-h-[90vh] overflow-y-auto">
        <div v-if="detailLoading" class="py-10 flex items-center justify-center gap-2 text-muted-foreground">
          <Loader2 class="w-4 h-4 animate-spin" /> Đang tải…
        </div>
        <template v-else-if="detail">
          <DialogHeader>
            <DialogTitle>Lượt hỏi #{{ detail.id }}</DialogTitle>
            <DialogDescription>
              {{ fmt(detail.created_at) }} · {{ detail.user?.name || 'Khách' }} · model {{ detail.model || '—' }}
              <Badge v-if="!detail.in_scope" variant="secondary" class="ml-1 bg-amber-50 text-amber-600">Ngoài phạm vi</Badge>
            </DialogDescription>
          </DialogHeader>

          <div>
            <div class="text-xs font-semibold text-muted-foreground uppercase mb-1.5">Câu hỏi của người dùng</div>
            <p class="text-sm bg-muted rounded-lg p-3 whitespace-pre-wrap">{{ detail.user_message }}</p>
          </div>

          <div>
            <div class="text-xs font-semibold text-muted-foreground uppercase mb-1.5">
              Prompt cá nhân hóa cuối cùng gửi Gemini (systemInstruction)
            </div>
            <pre
              v-if="detail.final_prompt"
              class="text-xs font-mono bg-muted rounded-lg p-3 whitespace-pre-wrap max-h-96 overflow-y-auto"
            >{{ detail.final_prompt }}</pre>
            <p v-else class="text-sm text-muted-foreground italic">
              Không có — câu hỏi bị chặn ở bước phân loại phạm vi (ngoài chủ đề dinh dưỡng/tập luyện) nên không gọi tới prompt tư vấn cá nhân hóa.
            </p>
          </div>

          <div>
            <div class="text-xs font-semibold text-muted-foreground uppercase mb-1.5">Câu trả lời</div>
            <p class="text-sm bg-muted rounded-lg p-3 whitespace-pre-wrap">{{ detail.reply || '—' }}</p>
          </div>
        </template>
      </DialogContent>
    </Dialog>
  </div>
</template>
