<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { useAdmin } from '@/composables/useAdmin'
import { useAuth } from '@/composables/useAuth'
import { useToast } from '@/composables/useToast'
import type { DishRow, DishInput } from '@/types/admin'

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
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <h1 class="text-xl font-bold text-gray-900">Thư viện món ăn</h1>
      <div class="flex items-center gap-2">
        <input
          v-model="search" @keydown.enter="load(1)" placeholder="Tìm món…"
          class="px-3 py-2 text-sm border border-gray-200 rounded-lg bg-white w-44"
        />
        <button class="px-4 py-2 text-sm font-medium rounded-lg bg-calor-green text-white hover:bg-calor-deep" @click="openCreate">
          + Thêm món
        </button>
      </div>
    </div>

    <p class="text-sm text-gray-500 mb-3">
      Calo/macro các món này được dùng làm chuẩn khi nhận diện (grounding). Tên + biệt danh càng đầy đủ thì AI càng dễ khớp.
    </p>

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
              <th class="text-left font-medium px-4 py-3">Món</th>
              <th class="text-left font-medium px-4 py-3">Đơn vị</th>
              <th class="text-right font-medium px-4 py-3">Calo</th>
              <th class="text-right font-medium px-4 py-3">P / C / F</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="5" class="px-4 py-10 text-center text-gray-400">Đang tải…</td></tr>
            <tr v-else-if="!rows.length"><td colspan="5" class="px-4 py-10 text-center text-gray-400">Chưa có món nào</td></tr>
            <tr v-for="d in rows" :key="d.id" class="hover:bg-gray-50">
              <td class="px-4 py-3">
                <div class="font-medium text-gray-800">{{ d.name }}</div>
                <div v-if="d.aliases.length" class="text-xs text-gray-400 truncate max-w-xs">{{ d.aliases.join(', ') }}</div>
              </td>
              <td class="px-4 py-3 text-gray-500">
                <span class="text-xs px-2 py-0.5 rounded-full" :class="d.unit_type === 'countable' ? 'bg-blue-50 text-blue-600' : 'bg-amber-50 text-amber-600'">
                  {{ d.unit_label }}
                </span>
                <span class="text-xs text-gray-400 ml-1">{{ d.serving }}</span>
              </td>
              <td class="px-4 py-3 text-right font-semibold text-gray-800 tabular-nums">{{ d.calories }}</td>
              <td class="px-4 py-3 text-right text-gray-500 tabular-nums">{{ d.protein }} / {{ d.carbs }} / {{ d.fat }}</td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <button class="text-calor-green hover:underline mr-3" @click="openEdit(d)">Sửa</button>
                <button class="text-red-500 hover:underline" @click="remove(d)">Xoá</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="flex items-center justify-between px-4 py-3 border-t border-gray-100 text-sm text-gray-500">
        <span>Tổng {{ meta.total }} món</span>
        <div class="flex items-center gap-1">
          <button class="px-2 py-1 rounded hover:bg-gray-100 disabled:opacity-40" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">‹</button>
          <span class="px-2">Trang {{ meta.current_page }} / {{ meta.last_page }}</span>
          <button class="px-2 py-1 rounded hover:bg-gray-100 disabled:opacity-40" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">›</button>
        </div>
      </div>
    </div>

    <!-- Modal thêm/sửa -->
    <div v-if="modalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40" @click.self="modalOpen = false">
      <div class="bg-white rounded-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto p-5">
        <h2 class="text-lg font-bold text-gray-900 mb-4">{{ editingId ? 'Sửa món' : 'Thêm món' }}</h2>
        <div class="space-y-3">
          <label class="block">
            <span class="text-xs text-gray-500">Tên món (canonical)</span>
            <input v-model="form.name" class="w-full mt-1 px-3 py-2 text-sm border border-gray-200 rounded-lg" placeholder="vd: Phở bò" />
          </label>
          <label class="block">
            <span class="text-xs text-gray-500">Biệt danh (phân cách bằng dấu phẩy)</span>
            <input v-model="aliasesText" class="w-full mt-1 px-3 py-2 text-sm border border-gray-200 rounded-lg" placeholder="pho, pho bo tai" />
          </label>
          <div class="grid grid-cols-3 gap-3">
            <label class="block">
              <span class="text-xs text-gray-500">Loại</span>
              <select v-model="form.unit_type" class="w-full mt-1 px-3 py-2 text-sm border border-gray-200 rounded-lg bg-white">
                <option value="portion">Khẩu phần</option>
                <option value="countable">Đếm được</option>
              </select>
            </label>
            <label class="block">
              <span class="text-xs text-gray-500">Đơn vị</span>
              <input v-model="form.unit_label" class="w-full mt-1 px-3 py-2 text-sm border border-gray-200 rounded-lg" placeholder="tô / cái" />
            </label>
            <label class="block">
              <span class="text-xs text-gray-500">Khẩu phần</span>
              <input v-model="form.serving" class="w-full mt-1 px-3 py-2 text-sm border border-gray-200 rounded-lg" placeholder="1 tô" />
            </label>
          </div>
          <div class="grid grid-cols-5 gap-2">
            <label class="block"><span class="text-xs text-gray-500">Calo</span><input v-model.number="form.calories" type="number" class="w-full mt-1 px-2 py-2 text-sm border border-gray-200 rounded-lg tabular-nums" /></label>
            <label class="block"><span class="text-xs text-gray-500">Đạm</span><input v-model.number="form.protein" type="number" class="w-full mt-1 px-2 py-2 text-sm border border-gray-200 rounded-lg tabular-nums" /></label>
            <label class="block"><span class="text-xs text-gray-500">Tinh bột</span><input v-model.number="form.carbs" type="number" class="w-full mt-1 px-2 py-2 text-sm border border-gray-200 rounded-lg tabular-nums" /></label>
            <label class="block"><span class="text-xs text-gray-500">Béo</span><input v-model.number="form.fat" type="number" class="w-full mt-1 px-2 py-2 text-sm border border-gray-200 rounded-lg tabular-nums" /></label>
            <label class="block"><span class="text-xs text-gray-500">Natri</span><input v-model.number="form.sodium" type="number" class="w-full mt-1 px-2 py-2 text-sm border border-gray-200 rounded-lg tabular-nums" /></label>
          </div>
        </div>
        <div class="flex justify-end gap-2 mt-5">
          <button class="px-4 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-100" @click="modalOpen = false">Huỷ</button>
          <button class="px-4 py-2 text-sm font-medium rounded-lg bg-calor-green text-white hover:bg-calor-deep disabled:opacity-50" :disabled="saving" @click="save">
            {{ saving ? 'Đang lưu…' : 'Lưu' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
