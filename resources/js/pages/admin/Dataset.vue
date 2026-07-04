<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useAdmin } from '@/composables/useAdmin'
import { useAuth } from '@/composables/useAuth'
import { useToast } from '@/composables/useToast'
import type { DatasetStats, DatasetRow, DatasetDetail } from '@/types/admin'

const { fetchDatasetStats, fetchDataset, fetchDatasetSample, deleteDatasetSample } = useAdmin()
const { extractError } = useAuth()
const toast = useToast()

const stats = ref<DatasetStats | null>(null)
const rows = ref<DatasetRow[]>([])
const loading = ref(true)
const meta = ref({ current_page: 1, per_page: 20, total: 0, last_page: 1 })
const onlyCorrections = ref(false)

const detail = ref<DatasetDetail | null>(null)
const detailLoading = ref(false)

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

async function openDetail(id: number) {
  detailLoading.value = true
  detail.value = null
  try {
    detail.value = await fetchDatasetSample(id)
  } catch (e) {
    toast.error(extractError(e) || 'Không tải được chi tiết')
  } finally {
    detailLoading.value = false
  }
}

async function remove(id: number) {
  if (!confirm('Xoá mẫu này khỏi dataset?')) return
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

onMounted(() => load())
</script>

<template>
  <div>
    <h1 class="text-xl font-bold text-gray-900 mb-1">Dataset nhận diện</h1>
    <p class="text-sm text-gray-500 mb-4">
      AI đoán vs người dùng sửa. Dùng để xem model sai ở đâu và chọn món bổ sung vào thư viện.
    </p>

    <!-- Stats -->
    <div v-if="stats" class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
      <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="text-2xl font-bold text-gray-900">{{ stats.total }}</div>
        <div class="text-xs text-gray-500">Tổng mẫu</div>
      </div>
      <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="text-2xl font-bold text-amber-600">{{ stats.with_correction }}</div>
        <div class="text-xs text-gray-500">Có sửa (tín hiệu mạnh)</div>
      </div>
      <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="text-2xl font-bold text-calor-green">{{ stats.saved }}</div>
        <div class="text-xs text-gray-500">Đã ghi nhật ký</div>
      </div>
      <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="text-2xl font-bold text-blue-600">{{ stats.with_image }}</div>
        <div class="text-xs text-gray-500">Có ảnh</div>
      </div>
    </div>

    <label class="inline-flex items-center gap-2 text-sm text-gray-600 mb-3 cursor-pointer">
      <input type="checkbox" v-model="onlyCorrections" @change="load(1)" class="rounded" />
      Chỉ hiện mẫu có sửa
    </label>

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
              <th class="text-left font-medium px-4 py-3">#</th>
              <th class="text-left font-medium px-4 py-3">Nguồn</th>
              <th class="text-left font-medium px-4 py-3">Món</th>
              <th class="text-left font-medium px-4 py-3">Trạng thái</th>
              <th class="text-left font-medium px-4 py-3">Thời gian</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="6" class="px-4 py-10 text-center text-gray-400">Đang tải…</td></tr>
            <tr v-else-if="!rows.length"><td colspan="6" class="px-4 py-10 text-center text-gray-400">Chưa có mẫu nào</td></tr>
            <tr v-for="r in rows" :key="r.id" class="hover:bg-gray-50 cursor-pointer" @click="openDetail(r.id)">
              <td class="px-4 py-3 text-gray-400 tabular-nums">{{ r.id }}</td>
              <td class="px-4 py-3">
                <span class="text-xs px-2 py-0.5 rounded-full" :class="r.input_type === 'image' ? 'bg-blue-50 text-blue-600' : 'bg-gray-100 text-gray-600'">
                  {{ r.input_type === 'image' ? '📷 Ảnh' : '✍️ Mô tả' }}
                </span>
              </td>
              <td class="px-4 py-3 text-gray-700">{{ r.ai_count }} món</td>
              <td class="px-4 py-3">
                <span v-if="r.has_correction" class="text-xs px-2 py-0.5 rounded-full bg-amber-50 text-amber-600 mr-1">Đã sửa</span>
                <span v-if="r.saved" class="text-xs px-2 py-0.5 rounded-full bg-calor-green/10 text-calor-deep">Đã lưu</span>
                <span v-if="!r.has_correction && !r.saved" class="text-xs text-gray-400">—</span>
              </td>
              <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ fmt(r.created_at) }}</td>
              <td class="px-4 py-3 text-right">
                <button class="text-red-500 hover:underline" @click.stop="remove(r.id)">Xoá</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="flex items-center justify-between px-4 py-3 border-t border-gray-100 text-sm text-gray-500">
        <span>Tổng {{ meta.total }} mẫu</span>
        <div class="flex items-center gap-1">
          <button class="px-2 py-1 rounded hover:bg-gray-100 disabled:opacity-40" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">‹</button>
          <span class="px-2">Trang {{ meta.current_page }} / {{ meta.last_page }}</span>
          <button class="px-2 py-1 rounded hover:bg-gray-100 disabled:opacity-40" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">›</button>
        </div>
      </div>
    </div>

    <!-- Detail modal -->
    <div v-if="detailLoading || detail" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40" @click.self="detail = null">
      <div class="bg-white rounded-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto p-5">
        <div v-if="detailLoading" class="py-10 text-center text-gray-400">Đang tải…</div>
        <template v-else-if="detail">
          <div class="flex items-center justify-between mb-3">
            <h2 class="text-lg font-bold text-gray-900">Mẫu #{{ detail.id }}</h2>
            <button class="text-gray-400 hover:text-gray-600 text-xl leading-none" @click="detail = null">✕</button>
          </div>
          <div class="text-xs text-gray-500 mb-4">
            {{ fmt(detail.created_at) }} · model {{ detail.model || '—' }}
            <span v-if="detail.has_correction" class="ml-1 text-amber-600">· đã sửa</span>
          </div>

          <img v-if="detail.image" :src="detail.image" alt="" class="w-full max-h-72 object-contain rounded-lg bg-gray-50 mb-4" />
          <p v-if="detail.text_input" class="text-sm text-gray-700 bg-gray-50 rounded-lg p-3 mb-4">“{{ detail.text_input }}”</p>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <div class="text-xs font-semibold text-gray-500 uppercase mb-2">AI đoán</div>
              <ul class="space-y-1.5">
                <li v-for="(d, i) in detail.ai_dishes" :key="i" class="text-sm bg-gray-50 rounded-lg px-3 py-2">
                  <div class="font-medium text-gray-800">{{ d.food_name }}</div>
                  <div class="text-xs text-gray-400">{{ d.calories }} kcal · {{ d.serving || '—' }}</div>
                </li>
              </ul>
            </div>
            <div>
              <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Người dùng chốt</div>
              <ul v-if="detail.corrected_dishes" class="space-y-1.5">
                <li
                  v-for="(c, i) in detail.corrected_dishes" :key="i"
                  class="text-sm rounded-lg px-3 py-2"
                  :class="!c.selected ? 'bg-red-50 line-through text-gray-400' : 'bg-calor-green/5'"
                >
                  <div class="font-medium" :class="c.selected ? 'text-gray-800' : ''">
                    {{ c.food_name }}
                    <span v-if="detail.ai_dishes[i] && c.food_name !== detail.ai_dishes[i].food_name" class="text-xs text-amber-600">(đổi tên)</span>
                  </div>
                  <div class="text-xs text-gray-400">{{ c.calories }} kcal · SL {{ c.quantity }}</div>
                </li>
              </ul>
              <p v-else class="text-sm text-gray-400">Chưa có phản hồi (user chưa chốt).</p>
            </div>
          </div>

          <div class="flex justify-end mt-5">
            <button class="px-4 py-2 text-sm text-red-500 hover:bg-red-50 rounded-lg" @click="remove(detail.id)">Xoá mẫu này</button>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
