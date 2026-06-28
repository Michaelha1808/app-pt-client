<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'
import { useHealthIntegration } from '@/composables/useHealthIntegration'
import { useMealLog } from '@/composables/useMealLog'
import { useToast } from '@/composables/useToast'
import { ACTIVITY_TYPES, activityMeta, type ActivityType } from '@/types/health'

const router = useRouter()
const route = useRoute()
const toast = useToast()
const {
  activities, loading, hasMore, isConnected, isAvailable,
  fetchConnections, fetchActivities, logManual, deleteActivity, connect, disconnect,
} = useHealthIntegration()
const { todayStats, fetchTodayStats } = useMealLog()

const connecting = ref(false)

async function connectStrava() {
  connecting.value = true
  try {
    await connect('strava')   // redirect cả tab
  } catch {
    connecting.value = false
    toast.error('Không thể bắt đầu kết nối Strava')
  }
}

async function disconnectStrava() {
  if (!confirm('Ngắt kết nối Strava?')) return
  const ok = await disconnect('strava')
  toast[ok ? 'success' : 'error'](ok ? 'Đã ngắt kết nối' : 'Không thể ngắt kết nối')
}

const showForm = ref(false)
const saving   = ref(false)

// Form state
const form = reactive({
  type: 'walk' as ActivityType,
  minutes: 30,
  distance_km: '' as string,
  calories: '' as string,
  name: '' as string,
})

const burnedToday = computed(() => todayStats.value?.calories_burned ?? 0)

function resetForm() {
  form.type = 'walk'
  form.minutes = 30
  form.distance_km = ''
  form.calories = ''
  form.name = ''
}

function openForm() {
  resetForm()
  showForm.value = true
}

async function submit() {
  if (form.minutes <= 0) { toast.error('Thời lượng phải lớn hơn 0'); return }
  saving.value = true
  const created = await logManual({
    type: form.type,
    duration_seconds: Math.round(form.minutes * 60),
    distance_meters: form.distance_km ? Math.round(parseFloat(form.distance_km) * 1000) : null,
    calories: form.calories ? parseInt(form.calories) : null,
    name: form.name || null,
  })
  saving.value = false
  if (created) {
    toast.success(`Đã thêm buổi tập · ${created.calories ?? 0} kcal`)
    showForm.value = false
    fetchTodayStats()   // cập nhật calo đốt trên Home
  } else {
    toast.error('Không thể lưu buổi tập')
  }
}

async function remove(id: number) {
  if (!confirm('Xoá buổi tập này?')) return
  const ok = await deleteActivity(id)
  if (ok) { toast.success('Đã xoá'); fetchTodayStats() }
}

function fmtDuration(sec: number): string {
  const m = Math.round(sec / 60)
  if (m < 60) return `${m} phút`
  const h = Math.floor(m / 60)
  return `${h}h${m % 60 ? ` ${m % 60}p` : ''}`
}

function fmtDistance(m: number | null): string | null {
  if (!m) return null
  return m >= 1000 ? `${(m / 1000).toFixed(1)} km` : `${m} m`
}

function fmtDate(iso: string): string {
  return new Date(iso).toLocaleDateString('vi-VN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

onMounted(() => {
  fetchConnections()
  fetchActivities(true)
  if (!todayStats.value) fetchTodayStats()

  // Kết quả trả về từ trang callback (/integrations/callback redirect kèm ?status=&connected=)
  const status = route.query.status as string | undefined
  if (status === 'success') {
    toast.success('Đã kết nối Strava · đang đồng bộ buổi tập...')
    setTimeout(() => { fetchConnections(); fetchActivities(true); fetchTodayStats() }, 1500)
  } else if (status && status !== 'success') {
    toast.error('Kết nối Strava không thành công')
  }
  if (status) router.replace({ query: {} })
})
</script>

<template>
  <div class="pb-24">
    <!-- Header -->
    <div class="flex items-center gap-3 px-4 pt-2 pb-4">
      <button class="ios-press p-1 -ml-1" @click="router.back()">
        <svg viewBox="0 0 24 24" class="w-6 h-6" fill="#007AFF">
          <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
        </svg>
      </button>
      <h1 class="text-[17px] font-semibold text-black">Hoạt động</h1>
    </div>

    <!-- Tổng calo đốt hôm nay -->
    <div class="mx-4 mb-4 bg-gradient-to-r from-ios-orange/10 to-ios-yellow/10 rounded-[18px] px-5 py-4 flex items-center gap-4">
      <div class="w-12 h-12 rounded-full bg-ios-orange/15 flex items-center justify-center text-2xl">🔥</div>
      <div class="flex-1">
        <p class="text-[12px] text-ios-gray uppercase tracking-wider font-medium">Calo đốt hôm nay</p>
        <p class="text-[26px] font-bold text-black leading-tight">{{ burnedToday.toLocaleString('vi') }} <span class="text-[14px] font-medium text-ios-gray">kcal</span></p>
      </div>
    </div>

    <!-- Kết nối app sức khoẻ (Strava) — Phase B -->
    <div class="mx-4 mb-4">
      <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-2 px-1">Kết nối ứng dụng</p>
      <div class="bg-white rounded-[16px] px-4 py-3.5 flex items-center gap-3 shadow-sm">
        <div class="w-9 h-9 rounded-[8px] bg-[#FC4C02]/12 flex items-center justify-center text-lg">🟧</div>
        <div class="flex-1 min-w-0">
          <p class="text-[15px] font-medium text-black">Strava</p>
          <p class="text-[12px] text-ios-gray mt-0.5">
            <template v-if="isConnected('strava')">Đã kết nối · tự đồng bộ buổi tập</template>
            <template v-else>Tự động đồng bộ buổi chạy/đạp xe</template>
          </p>
        </div>
        <!-- Đã kết nối → nút ngắt -->
        <button
          v-if="isConnected('strava')"
          class="text-[13px] text-ios-red font-medium ios-press"
          @click="disconnectStrava"
        >Ngắt</button>
        <!-- Có credential → nút kết nối -->
        <button
          v-else-if="isAvailable('strava')"
          class="text-[13px] text-white font-semibold bg-[#FC4C02] px-3.5 py-1.5 rounded-full ios-press disabled:opacity-50"
          :disabled="connecting"
          @click="connectStrava"
        >{{ connecting ? '...' : 'Kết nối' }}</button>
        <!-- Chưa cấu hình key (Phase 0) -->
        <span v-else class="text-[12px] text-ios-gray bg-ios-gray6 px-2.5 py-1 rounded-full">Sắp ra mắt</span>
      </div>
    </div>

    <!-- Feed -->
    <div class="mx-4">
      <div class="flex items-center justify-between mb-2 px-1">
        <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide">Buổi tập gần đây</p>
        <button class="text-[14px] text-ios-blue font-semibold ios-press" @click="openForm">+ Thêm</button>
      </div>

      <div v-if="loading && activities.length === 0" class="bg-white rounded-[16px] h-24 animate-pulse shadow-sm"/>

      <div v-else-if="activities.length === 0" class="bg-white rounded-[16px] px-4 py-8 flex flex-col items-center gap-2 text-center shadow-sm">
        <span class="text-4xl">🏃</span>
        <p class="text-[14px] text-ios-gray">Chưa có buổi tập nào</p>
        <button class="mt-1 px-4 py-2 bg-ios-blue text-white text-[14px] font-semibold rounded-[10px] ios-press" @click="openForm">
          Thêm buổi tập đầu tiên
        </button>
      </div>

      <div v-else class="bg-white rounded-[16px] overflow-hidden shadow-sm">
        <div v-for="(a, idx) in activities" :key="a.id">
          <div class="flex items-center gap-3 px-4 py-3.5">
            <div class="w-10 h-10 rounded-[10px] bg-ios-gray6 flex items-center justify-center text-xl flex-shrink-0">
              {{ activityMeta(a.type).emoji }}
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-[15px] font-medium text-black truncate">
                {{ a.name || activityMeta(a.type).label }}
                <span v-if="a.source === 'manual'" class="text-[11px] text-ios-gray font-normal">· tự thêm</span>
              </p>
              <p class="text-[12px] text-ios-gray mt-0.5">
                {{ fmtDate(a.started_at) }} · {{ fmtDuration(a.duration_seconds) }}<template v-if="fmtDistance(a.distance_meters)"> · {{ fmtDistance(a.distance_meters) }}</template>
              </p>
            </div>
            <div class="text-right flex-shrink-0">
              <p class="text-[15px] font-semibold text-ios-orange">{{ a.calories ?? 0 }}</p>
              <p class="text-[11px] text-ios-gray">kcal</p>
            </div>
            <button v-if="a.source === 'manual'" class="ios-press p-1 text-ios-gray3" @click="remove(a.id)">
              <svg viewBox="0 0 24 24" class="w-5 h-5" fill="currentColor"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
            </button>
          </div>
          <div v-if="idx < activities.length - 1" class="ios-separator mx-4"/>
        </div>
      </div>

      <button
        v-if="hasMore"
        class="w-full mt-3 py-2.5 text-[14px] text-ios-blue font-medium ios-press"
        :disabled="loading"
        @click="fetchActivities(false)"
      >
        {{ loading ? 'Đang tải...' : 'Xem thêm' }}
      </button>
    </div>

    <!-- Form thêm buổi tập (bottom sheet) -->
    <Teleport to="body">
      <div v-if="showForm" class="fixed inset-0 z-50 flex items-end justify-center" @click.self="showForm = false">
        <div class="absolute inset-0 bg-black/40" @click="showForm = false"/>
        <div class="relative w-full max-w-md bg-white rounded-t-[24px] px-5 pt-3 pb-8 animate-slideUpSheet">
          <div class="w-10 h-1 bg-ios-gray4 rounded-full mx-auto mb-4"/>
          <h2 class="text-[18px] font-semibold text-black mb-4">Thêm buổi tập</h2>

          <!-- Loại bài tập -->
          <p class="text-[13px] font-medium text-ios-gray mb-2">Loại</p>
          <div class="grid grid-cols-4 gap-2 mb-4">
            <button
              v-for="t in ACTIVITY_TYPES" :key="t.value"
              class="flex flex-col items-center gap-1 py-2.5 rounded-[12px] ios-press transition-colors"
              :class="form.type === t.value ? 'bg-ios-blue/12 ring-1 ring-ios-blue' : 'bg-ios-gray6'"
              @click="form.type = t.value"
            >
              <span class="text-xl">{{ t.emoji }}</span>
              <span class="text-[11px]" :class="form.type === t.value ? 'text-ios-blue font-semibold' : 'text-ios-gray'">{{ t.label }}</span>
            </button>
          </div>

          <!-- Thời lượng -->
          <p class="text-[13px] font-medium text-ios-gray mb-2">Thời lượng (phút)</p>
          <div class="flex gap-2 mb-4">
            <button
              v-for="preset in [15, 30, 45, 60]" :key="preset"
              class="flex-1 py-2 rounded-[10px] text-[14px] font-medium ios-press"
              :class="form.minutes === preset ? 'bg-ios-blue text-white' : 'bg-ios-gray6 text-black'"
              @click="form.minutes = preset"
            >{{ preset }}'</button>
            <input
              v-model.number="form.minutes" type="number" min="1" max="1440"
              class="w-16 py-2 text-center rounded-[10px] bg-ios-gray6 text-[14px] font-medium focus:outline-none focus:ring-1 focus:ring-ios-blue"
            />
          </div>

          <!-- Tuỳ chọn: quãng đường + calo -->
          <div class="flex gap-3 mb-2">
            <div class="flex-1">
              <p class="text-[13px] font-medium text-ios-gray mb-1.5">Quãng đường (km)</p>
              <input v-model="form.distance_km" type="number" min="0" step="0.1" placeholder="—"
                class="w-full py-2.5 px-3 rounded-[10px] bg-ios-gray6 text-[14px] focus:outline-none focus:ring-1 focus:ring-ios-blue"/>
            </div>
            <div class="flex-1">
              <p class="text-[13px] font-medium text-ios-gray mb-1.5">Calo (tự tính nếu trống)</p>
              <input v-model="form.calories" type="number" min="0" placeholder="auto"
                class="w-full py-2.5 px-3 rounded-[10px] bg-ios-gray6 text-[14px] focus:outline-none focus:ring-1 focus:ring-ios-blue"/>
            </div>
          </div>

          <button
            class="w-full mt-5 py-3.5 bg-ios-blue text-white text-[16px] font-semibold rounded-[14px] ios-press disabled:opacity-50"
            :disabled="saving"
            @click="submit"
          >
            {{ saving ? 'Đang lưu...' : 'Lưu buổi tập' }}
          </button>
        </div>
      </div>
    </Teleport>
  </div>
</template>
