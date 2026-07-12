<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { useAdmin } from '@/composables/useAdmin'
import { useAuth } from '@/composables/useAuth'
import { useToast } from '@/composables/useToast'
import type { DishRow, DishInput } from '@/types/admin'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle,
} from '@/components/ui/dialog'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table'
import { ChevronLeft, ChevronRight, Loader2, Plus } from 'lucide-vue-next'

const { fetchDishes, createDish, updateDish, deleteDish } = useAdmin()
const { extractError } = useAuth()
const toast = useToast()

const rows = ref<DishRow[]>([])
const loading = ref(true)
const meta = ref({ current_page: 1, per_page: 20, total: 0, last_page: 1 })
const search = ref('')

const modalOpen = ref(false)
const editingId = ref<number | null>(null)
const saving = ref(false)
const aliasesText = ref('')

const blank = (): DishInput => ({
  name: '', aliases: [], unit_type: 'portion', unit_label: 'phần',
  serving: '1 khẩu phần', calories: 0, protein: 0, carbs: 0, fat: 0, sodium: 0,
})
const form = reactive<DishInput>(blank())

async function load(page = 1) {
  loading.value = true
  try {
    const res = await fetchDishes({ q: search.value || undefined, page })
    rows.value = res.data
    meta.value = res.meta
  } catch (e) {
    toast.error(extractError(e) || 'Không tải được thư viện món')
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editingId.value = null
  Object.assign(form, blank())
  aliasesText.value = ''
  modalOpen.value = true
}

function openEdit(d: DishRow) {
  editingId.value = d.id
  Object.assign(form, {
    name: d.name, aliases: d.aliases, unit_type: d.unit_type, unit_label: d.unit_label,
    serving: d.serving, calories: d.calories, protein: d.protein, carbs: d.carbs, fat: d.fat, sodium: d.sodium,
  })
  aliasesText.value = d.aliases.join(', ')
  modalOpen.value = true
}

async function save() {
  if (!form.name.trim()) { toast.error('Tên món không được trống'); return }
  saving.value = true
  form.aliases = aliasesText.value.split(',').map(s => s.trim()).filter(Boolean)
  try {
    if (editingId.value) {
      await updateDish(editingId.value, { ...form })
      toast.success('Đã cập nhật món')
    } else {
      await createDish({ ...form })
      toast.success('Đã thêm món')
    }
    modalOpen.value = false
    await load(meta.value.current_page)
  } catch (e) {
    toast.error(extractError(e) || 'Lưu thất bại')
  } finally {
    saving.value = false
  }
}

async function remove(d: DishRow) {
  if (!confirm(`Xoá món "${d.name}" khỏi thư viện?`)) return
  try {
    await deleteDish(d.id)
    toast.success('Đã xoá món')
    await load(meta.value.current_page)
  } catch (e) {
    toast.error(extractError(e) || 'Xoá thất bại')
  }
}

onMounted(() => load())
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-5 flex-wrap gap-2">
      <div>
        <h1 class="text-xl font-bold">Thư viện món ăn</h1>
        <p class="text-sm text-muted-foreground mt-0.5">Nutrition DB chuẩn để grounding kết quả AI.</p>
      </div>
      <div class="flex items-center gap-2">
        <Input v-model="search" placeholder="Tìm món…" class="w-44" @keydown.enter="load(1)" />
        <Button @click="openCreate">
          <Plus class="w-4 h-4" /> Thêm món
        </Button>
      </div>
    </div>

    <p class="text-sm text-muted-foreground mb-3">
      Calo/macro các món này được dùng làm chuẩn khi nhận diện (grounding). Tên + biệt danh càng đầy đủ thì AI càng dễ khớp.
    </p>

    <Card class="py-0 gap-0 overflow-hidden">
      <div class="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Món</TableHead>
              <TableHead>Đơn vị</TableHead>
              <TableHead class="text-right">Calo</TableHead>
              <TableHead class="text-right">P / C / F</TableHead>
              <TableHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            <template v-if="loading">
              <TableRow v-for="i in 5" :key="i">
                <TableCell v-for="j in 5" :key="j"><Skeleton class="h-4 w-full" /></TableCell>
              </TableRow>
            </template>
            <TableRow v-else-if="!rows.length">
              <TableCell colspan="5" class="py-10 text-center text-muted-foreground">Chưa có món nào</TableCell>
            </TableRow>
            <TableRow v-for="d in rows" v-else :key="d.id">
              <TableCell>
                <div class="font-medium">{{ d.name }}</div>
                <div v-if="d.aliases.length" class="text-xs text-muted-foreground truncate max-w-xs">{{ d.aliases.join(', ') }}</div>
              </TableCell>
              <TableCell>
                <Badge variant="secondary" :class="d.unit_type === 'countable' ? 'bg-blue-50 text-blue-600' : 'bg-amber-50 text-amber-600'">
                  {{ d.unit_label }}
                </Badge>
                <span class="text-xs text-muted-foreground ml-1">{{ d.serving }}</span>
              </TableCell>
              <TableCell class="text-right font-semibold tabular-nums">{{ d.calories }}</TableCell>
              <TableCell class="text-right text-muted-foreground tabular-nums">{{ d.protein }} / {{ d.carbs }} / {{ d.fat }}</TableCell>
              <TableCell class="text-right whitespace-nowrap">
                <Button variant="ghost" size="sm" class="h-7 text-calor-green hover:text-calor-dark" @click="openEdit(d)">Sửa</Button>
                <Button variant="ghost" size="sm" class="h-7 text-destructive hover:text-destructive" @click="remove(d)">Xoá</Button>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>
      <div class="flex items-center justify-between px-4 py-3 border-t text-sm text-muted-foreground">
        <span>Tổng {{ meta.total }} món</span>
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

    <!-- Dialog thêm/sửa -->
    <Dialog v-model:open="modalOpen">
      <DialogContent class="max-w-lg max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{{ editingId ? 'Sửa món' : 'Thêm món' }}</DialogTitle>
        </DialogHeader>
        <div class="space-y-3">
          <div class="space-y-1.5">
            <Label>Tên món (canonical)</Label>
            <Input v-model="form.name" placeholder="vd: Phở bò" />
          </div>
          <div class="space-y-1.5">
            <Label>Biệt danh (phân cách bằng dấu phẩy)</Label>
            <Input v-model="aliasesText" placeholder="pho, pho bo tai" />
          </div>
          <div class="grid grid-cols-3 gap-3">
            <div class="space-y-1.5">
              <Label>Loại</Label>
              <Select v-model="form.unit_type">
                <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="portion">Khẩu phần</SelectItem>
                  <SelectItem value="countable">Đếm được</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div class="space-y-1.5">
              <Label>Đơn vị</Label>
              <Input v-model="form.unit_label" placeholder="tô / cái" />
            </div>
            <div class="space-y-1.5">
              <Label>Khẩu phần</Label>
              <Input v-model="form.serving" placeholder="1 tô" />
            </div>
          </div>
          <div class="grid grid-cols-5 gap-2">
            <div class="space-y-1.5"><Label>Calo</Label><Input v-model.number="form.calories" type="number" class="tabular-nums" /></div>
            <div class="space-y-1.5"><Label>Đạm</Label><Input v-model.number="form.protein" type="number" class="tabular-nums" /></div>
            <div class="space-y-1.5"><Label>Tinh bột</Label><Input v-model.number="form.carbs" type="number" class="tabular-nums" /></div>
            <div class="space-y-1.5"><Label>Béo</Label><Input v-model.number="form.fat" type="number" class="tabular-nums" /></div>
            <div class="space-y-1.5"><Label>Natri</Label><Input v-model.number="form.sodium" type="number" class="tabular-nums" /></div>
          </div>
        </div>
        <DialogFooter>
          <Button variant="ghost" @click="modalOpen = false">Huỷ</Button>
          <Button :disabled="saving" @click="save">
            <Loader2 v-if="saving" class="w-4 h-4 animate-spin" />
            {{ saving ? 'Đang lưu…' : 'Lưu' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
