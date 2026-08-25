<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useAdmin } from '@/composables/useAdmin'
import { useAuth } from '@/composables/useAuth'
import { useToast } from '@/composables/useToast'
import type { DatasetStats, DatasetRow, DatasetDetail, DatasetAccuracy, SampleAccuracy } from '@/types/admin'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table'
import {
  AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
  AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import EmptyState from '@/components/admin/EmptyState.vue'
import IconAction from '@/components/admin/IconAction.vue'
import { ChevronLeft, ChevronRight, Eye, Trash2, Database, Loader2, Image as ImageIcon, PenLine } from 'lucide-vue-next'

const { fetchDatasetStats, fetchDataset, fetchDatasetSample, deleteDatasetSample, fetchDatasetAccuracy, fetchSampleAccuracy } = useAdmin()
const { extractError } = useAuth()
const toast = useToast()

const stats = ref<DatasetStats | null>(null)
const rows = ref<DatasetRow[]>([])
const loading = ref(true)
const meta = ref({ current_page: 1, per_page: 20, total: 0, last_page: 1 })
const onlyCorrections = ref(false)

const detail = ref<DatasetDetail | null>(null)
const detailLoading = ref(false)

// Đo chất lượng nhận diện AI so với VDD — cửa sổ 30 ngày mặc định
const accuracy = ref<DatasetAccuracy | null>(null)
const accuracyLoading = ref(false)
const accuracyDays = ref(30)

// Per-sample accuracy khi mở dialog xem chi tiết
const sampleAccuracy = ref<SampleAccuracy | null>(null)

const dialogOpen = computed({
  get: () => detailLoading.value || !!detail.value,
  set: (v: boolean) => { if (!v) detail.value = null },
})

async function load(page = 1) {
  loading.value = true
  try {
    const [s, res] = await Promise.all([
      fetchDatasetStats(),
      fetchDataset({ only_corrections: onlyCorrections.value || undefined, page }),
    ])
    stats.value = s
    rows.value = res.data
    meta.value = res.meta
  } catch (e) {
    toast.error(extractError(e) || 'Không tải được dataset')
  } finally {
    loading.value = false
  }
}

async function loadAccuracy() {
  accuracyLoading.value = true
  try {
    accuracy.value = await fetchDatasetAccuracy({ days: accuracyDays.value, limit: 200 })
  } catch (e) {
    toast.error(extractError(e) || 'Không tải được thống kê chất lượng')
  } finally {
    accuracyLoading.value = false
  }
}

async function openDetail(id: number) {
  detailLoading.value = true
  detail.value = null
  sampleAccuracy.value = null
  try {
    // Song song: chi tiết + accuracy chấm điểm — 1 dialog show cả 2
    const [d, a] = await Promise.all([
      fetchDatasetSample(id),
      fetchSampleAccuracy(id).catch(() => null),
    ])
    detail.value = d
    sampleAccuracy.value = a
  } catch (e) {
    toast.error(extractError(e) || 'Không tải được chi tiết')
  } finally {
    detailLoading.value = false
  }
}

const deleteId = ref<number | null>(null)

async function confirmRemove() {
  const id = deleteId.value
  if (id === null) return
  deleteId.value = null
  try {
    await deleteDatasetSample(id)
    toast.success('Đã xoá mẫu')
    if (detail.value?.id === id) detail.value = null
    await load(meta.value.current_page)
  } catch (e) {
    toast.error(extractError(e) || 'Xoá thất bại')
  }
}

function fmt(s: string): string {
  return new Date(s).toLocaleString('vi-VN')
}

const statCards = computed(() => {
  const s = stats.value
  if (!s) return []
  return [
    { label: 'Tổng mẫu', value: s.total, color: '' },
    { label: 'Có sửa (tín hiệu mạnh)', value: s.with_correction, color: 'text-amber-600' },
    { label: 'Đã ghi nhật ký', value: s.saved, color: 'text-calor-green' },
    { label: 'Có ảnh', value: s.with_image, color: 'text-blue-600' },
  ]
})

onMounted(() => { load(); loadAccuracy() })
</script>

<template>
  <div>
    <div class="mb-5">
      <h1 class="text-xl font-bold">Dataset nhận diện</h1>
      <p class="text-sm text-muted-foreground mt-0.5">
        AI đoán vs người dùng sửa. Dùng để xem model sai ở đâu và chọn món bổ sung vào thư viện.
      </p>
    </div>

    <!-- Stats -->
    <div v-if="stats" class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
      <Card v-for="c in statCards" :key="c.label" class="py-4">
        <CardContent class="px-4">
          <div class="text-2xl font-bold" :class="c.color">{{ c.value }}</div>
          <div class="text-xs text-muted-foreground">{{ c.label }}</div>
        </CardContent>
      </Card>
    </div>

    <!-- Chất lượng model — so calo AI đoán với chuẩn VDD -->
    <Card class="mb-4">
      <CardContent class="px-4">
        <div class="flex items-center justify-between mb-3">
          <div>
            <div class="text-sm font-semibold">Chất lượng nhận diện AI</div>
            <p class="text-xs text-muted-foreground">
              So calo AI ước tính với chuẩn Bảng Nhu cầu & Bảng Thành phần Thực phẩm VDD.
              Ngưỡng "đúng" ± {{ accuracy?.aggregate.tolerance_pct ?? 20 }}%.
            </p>
          </div>
          <div class="flex items-center gap-1.5 text-xs">
            <span class="text-muted-foreground">Cửa sổ:</span>
            <select v-model="accuracyDays" @change="loadAccuracy" class="text-xs border rounded px-1.5 py-1 bg-transparent">
              <option :value="7">7 ngày</option>
              <option :value="30">30 ngày</option>
              <option :value="90">90 ngày</option>
              <option :value="365">1 năm</option>
            </select>
            <Button variant="outline" size="sm" class="h-7 text-xs" :disabled="accuracyLoading" @click="loadAccuracy">
              <Loader2 v-if="accuracyLoading" class="w-3 h-3 animate-spin" /> Làm mới
            </Button>
          </div>
        </div>

        <div v-if="accuracy" class="grid grid-cols-2 lg:grid-cols-4 gap-3">
          <div class="rounded-lg bg-muted/50 p-3">
            <div class="text-xs text-muted-foreground">Coverage</div>
            <div class="text-2xl font-bold text-blue-600">{{ accuracy.aggregate.coverage_pct }}%</div>
            <div class="text-xs text-muted-foreground mt-0.5">{{ accuracy.aggregate.matched }}/{{ accuracy.aggregate.total_dishes }} món xác định được VDD</div>
          </div>
          <div class="rounded-lg bg-muted/50 p-3">
            <div class="text-xs text-muted-foreground">Accuracy</div>
            <div class="text-2xl font-bold" :class="accuracy.aggregate.accuracy_pct >= 70 ? 'text-calor-green' : accuracy.aggregate.accuracy_pct >= 50 ? 'text-amber-600' : 'text-red-600'">
              {{ accuracy.aggregate.accuracy_pct }}%
            </div>
            <div class="text-xs text-muted-foreground mt-0.5">{{ accuracy.aggregate.correct }}/{{ accuracy.aggregate.matched }} món trong ±{{ accuracy.aggregate.tolerance_pct }}%</div>
          </div>
          <div class="rounded-lg bg-muted/50 p-3">
            <div class="text-xs text-muted-foreground">MAPE</div>
            <div class="text-2xl font-bold" :class="accuracy.aggregate.mape_pct <= 20 ? 'text-calor-green' : accuracy.aggregate.mape_pct <= 40 ? 'text-amber-600' : 'text-red-600'">
              {{ accuracy.aggregate.mape_pct }}%
            </div>
            <div class="text-xs text-muted-foreground mt-0.5">Sai số kcal trung bình</div>
          </div>
          <div class="rounded-lg bg-muted/50 p-3">
            <div class="text-xs text-muted-foreground">Mẫu phân tích</div>
            <div class="text-2xl font-bold">{{ accuracy.window.sample_count }}</div>
            <div class="text-xs text-muted-foreground mt-0.5">Trong {{ accuracy.window.days }} ngày qua</div>
          </div>
        </div>

        <div v-if="accuracy && Object.keys(accuracy.aggregate.by_group).length" class="mt-4">
          <div class="text-xs font-semibold text-muted-foreground uppercase mb-2">Chi tiết theo nhóm thực phẩm</div>
          <div class="space-y-1.5">
            <div v-for="(g, name) in accuracy.aggregate.by_group" :key="name" class="flex items-center gap-2 text-sm">
              <span class="flex-1 truncate">{{ name }}</span>
              <span class="text-xs text-muted-foreground">{{ g.correct }}/{{ g.matched }}</span>
              <span class="text-xs font-medium w-14 text-right" :class="g.accuracy_pct >= 70 ? 'text-calor-green' : g.accuracy_pct >= 50 ? 'text-amber-600' : 'text-red-600'">{{ g.accuracy_pct }}%</span>
              <span class="text-xs text-muted-foreground w-16 text-right">MAPE {{ g.mape_pct }}%</span>
            </div>
          </div>
        </div>

        <div v-else-if="accuracyLoading" class="text-sm text-muted-foreground">Đang tính…</div>
        <div v-else class="text-sm text-muted-foreground">Chưa có mẫu nào để đánh giá.</div>
      </CardContent>
    </Card>

    <label class="inline-flex items-center gap-2 text-sm text-muted-foreground mb-3 cursor-pointer">
      <Switch v-model="onlyCorrections" @update:model-value="load(1)" />
      Chỉ hiện mẫu có sửa
    </label>

    <Card class="py-0 gap-0 overflow-hidden shadow-xs">
      <div class="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow class="hover:bg-transparent">
              <TableHead>#</TableHead>
              <TableHead>Nguồn</TableHead>
              <TableHead>Món</TableHead>
              <TableHead>Trạng thái</TableHead>
              <TableHead>Thời gian</TableHead>
              <TableHead class="text-right">Thao tác</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <template v-if="loading">
              <TableRow v-for="i in 5" :key="i">
                <TableCell v-for="j in 6" :key="j"><Skeleton class="h-4 w-full" /></TableCell>
              </TableRow>
            </template>
            <TableRow v-else-if="!rows.length" class="hover:bg-transparent">
              <TableCell colspan="6" class="p-0">
                <EmptyState :icon="Database" title="Chưa có mẫu nào" hint="Mẫu được thu tự động mỗi lần người dùng scan món ăn." />
              </TableCell>
            </TableRow>
            <TableRow v-for="r in rows" v-else :key="r.id" class="cursor-pointer" @click="openDetail(r.id)">
              <TableCell class="text-muted-foreground tabular-nums">{{ r.id }}</TableCell>
              <TableCell>
                <Badge variant="secondary" class="gap-1" :class="r.input_type === 'image' ? 'bg-blue-500/10 text-blue-600' : ''">
                  <ImageIcon v-if="r.input_type === 'image'" class="w-3 h-3" />
                  <PenLine v-else class="w-3 h-3" />
                  {{ r.input_type === 'image' ? 'Ảnh' : 'Mô tả' }}
                </Badge>
              </TableCell>
              <TableCell>{{ r.ai_count }} món</TableCell>
              <TableCell>
                <Badge v-if="r.has_correction" variant="secondary" class="bg-amber-50 text-amber-600 mr-1">Đã sửa</Badge>
                <Badge v-if="r.saved" variant="secondary" class="bg-calor-green/10 text-calor-deep">Đã lưu</Badge>
                <span v-if="!r.has_correction && !r.saved" class="text-xs text-muted-foreground">—</span>
              </TableCell>
              <TableCell class="text-muted-foreground whitespace-nowrap">{{ fmt(r.created_at) }}</TableCell>
              <TableCell class="text-right" @click.stop>
                <div class="inline-flex items-center gap-0.5">
                  <IconAction label="Xem chi tiết" tone="view" @click="openDetail(r.id)"><Eye /></IconAction>
                  <IconAction label="Xoá mẫu" tone="delete" @click="deleteId = r.id"><Trash2 /></IconAction>
                </div>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>
      <div class="flex items-center justify-between px-4 py-3 border-t text-sm text-muted-foreground">
        <span>Tổng {{ meta.total }} mẫu</span>
        <div class="flex items-center gap-1">
          <Button variant="outline" size="icon" class="h-8 w-8" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">
            <ChevronLeft class="w-4 h-4" />
          </Button>
          <span class="px-2">Trang {{ meta.current_page }} / {{ meta.last_page }}</span>
          <Button variant="outline" size="icon" class="h-8 w-8" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">
            <ChevronRight class="w-4 h-4" />
          </Button>
        </div>
      </div>
    </Card>

    <!-- Detail dialog -->
    <Dialog v-model:open="dialogOpen">
      <DialogContent class="max-w-2xl max-h-[90vh] overflow-y-auto">
        <div v-if="detailLoading" class="py-10 flex items-center justify-center gap-2 text-muted-foreground">
          <Loader2 class="w-4 h-4 animate-spin" /> Đang tải…
        </div>
        <template v-else-if="detail">
          <DialogHeader>
            <DialogTitle>Mẫu #{{ detail.id }}</DialogTitle>
            <DialogDescription>
              {{ fmt(detail.created_at) }} · model {{ detail.model || '—' }}
              <span v-if="detail.has_correction" class="ml-1 text-amber-600">· đã sửa</span>
            </DialogDescription>
          </DialogHeader>

          <img v-if="detail.image" :src="detail.image" alt="" class="w-full max-h-72 object-contain rounded-lg bg-muted" />
          <p v-if="detail.text_input" class="text-sm bg-muted rounded-lg p-3">“{{ detail.text_input }}”</p>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <div class="text-xs font-semibold text-muted-foreground uppercase mb-2">AI đoán</div>
              <ul class="space-y-1.5">
                <li v-for="(d, i) in detail.ai_dishes" :key="i" class="text-sm bg-muted rounded-lg px-3 py-2">
                  <div class="font-medium">{{ d.food_name }}</div>
                  <div class="text-xs text-muted-foreground">{{ d.calories }} kcal · {{ d.serving || '—' }}</div>
                </li>
              </ul>
            </div>
            <div>
              <div class="text-xs font-semibold text-muted-foreground uppercase mb-2">Người dùng chốt</div>
              <ul v-if="detail.corrected_dishes" class="space-y-1.5">
                <li
                  v-for="(c, i) in detail.corrected_dishes" :key="i"
                  class="text-sm rounded-lg px-3 py-2"
                  :class="!c.selected ? 'bg-red-50 line-through text-muted-foreground' : 'bg-calor-green/5'"
                >
                  <div class="font-medium">
                    {{ c.food_name }}
                    <span v-if="detail.ai_dishes[i] && c.food_name !== detail.ai_dishes[i].food_name" class="text-xs text-amber-600">(đổi tên)</span>
                  </div>
                  <div class="text-xs text-muted-foreground">{{ c.calories }} kcal · SL {{ c.quantity }}</div>
                </li>
              </ul>
              <p v-else class="text-sm text-muted-foreground">Chưa có phản hồi (user chưa chốt).</p>
            </div>
          </div>

          <!-- Đối chiếu với VDD — chỉ show nếu tính được accuracy -->
          <div v-if="sampleAccuracy && sampleAccuracy.dishes.length" class="border-t pt-3">
            <div class="text-xs font-semibold text-muted-foreground uppercase mb-2">
              Đối chiếu chuẩn VDD ({{ sampleAccuracy.correct }}/{{ sampleAccuracy.matched }} đúng)
            </div>
            <ul class="space-y-1.5">
              <li v-for="(d, i) in sampleAccuracy.dishes" :key="i" class="text-xs bg-muted/50 rounded-lg px-3 py-2">
                <div class="flex items-center gap-2">
                  <Badge v-if="d.source === 'unmatched'" variant="secondary" class="text-[10px]">Chưa xác định</Badge>
                  <Badge v-else-if="d.is_correct" class="bg-calor-green/15 text-calor-green text-[10px]">✓ Trong ±20%</Badge>
                  <Badge v-else variant="destructive" class="text-[10px]">Sai {{ d.error_pct > 0 ? '+' : '' }}{{ d.error_pct }}%</Badge>
                  <span class="text-[10px] text-muted-foreground">nguồn: {{ d.source === 'catalog' ? 'Catalog món' : d.source === 'fct' ? 'FCT VDD' : '—' }}</span>
                </div>
                <div class="mt-1">
                  <span class="font-medium">{{ d.ai_name }}</span>
                  <span class="text-muted-foreground"> AI = {{ d.ai_kcal }} kcal</span>
                </div>
                <div v-if="d.vdd_name" class="text-muted-foreground">
                  ↔ VDD "{{ d.vdd_name }}" = {{ d.vdd_kcal }} kcal ({{ d.reference_unit }})
                </div>
              </li>
            </ul>
          </div>

          <div class="flex justify-end">
            <Button variant="outline" class="text-destructive hover:text-destructive border-destructive/30 hover:bg-destructive/5" @click="deleteId = detail.id">
              <Trash2 class="w-4 h-4" /> Xoá mẫu này
            </Button>
          </div>
        </template>
      </DialogContent>
    </Dialog>

    <!-- Confirm xoá mẫu -->
    <AlertDialog :open="deleteId !== null" @update:open="(v: boolean) => { if (!v) deleteId = null }">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle class="flex items-center gap-2"><Trash2 class="w-4 h-4 text-destructive" /> Xoá mẫu #{{ deleteId }}?</AlertDialogTitle>
          <AlertDialogDescription>Mẫu sẽ bị xoá vĩnh viễn khỏi dataset huấn luyện.</AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Huỷ</AlertDialogCancel>
          <AlertDialogAction class="bg-destructive text-white hover:bg-destructive/90" @click="confirmRemove">Xoá</AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  </div>
</template>
