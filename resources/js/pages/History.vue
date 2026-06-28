<script setup lang="ts">
import { useMealLog } from '@/composables/useMealLog'
import { useHealthIntegration } from '@/composables/useHealthIntegration'
import { activityMeta } from '@/types/health'
import CaloeyeCharacter from '@/components/caloeye/Character.vue'
import { useAuthStore } from '@/stores/auth'

const store = useAuthStore()
const { historyStats, loading, fetchHistory, deleteLog } = useMealLog()
const { activities, loading: actLoading, hasMore, fetchActivities } = useHealthIntegration()

const activeTab    = ref<'food' | 'exercise'>('food')
const selectedDate = ref(todayStr())

function todayStr() {
  return new Date().toISOString().slice(0, 10)
}

// Tổng calo đốt hôm nay = các buổi tập có started_at trong ngày hôm nay
const todayBurned = computed(() =>
  activities.value
    .filter(a => a.started_at.slice(0, 10) === todayStr())
    .reduce((s, a) => s + (a.calories ?? 0), 0),
)

function fmtActDuration(sec: number): string {
  const m = Math.round(sec / 60)
  if (m < 60) return `${m} phút`
  const h = Math.floor(m / 60)
  return `${h}h${m % 60 ? ` ${m % 60}p` : ''}`
}

function fmtActDistance(m: number | null): string | null {
  if (!m) return null
  return m >= 1000 ? `${(m / 1000).toFixed(1)} km` : `${m} m`
}

function fmtActDate(iso: string): string {
  return new Date(iso).toLocaleDateString('vi-VN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

// Lần đầu mở tab Tập luyện mới tải dữ liệu (lazy)
watch(activeTab, (t) => {
  if (t === 'exercise' && store.token && activities.value.length === 0) fetchActivities(true)
})

const weekDays  = computed(() => historyStats.value?.week ?? [])
const meals     = computed(() => historyStats.value?.meals ?? [])
const goal      = computed(() => store.user?.calorie_goal ?? 2000)
const totalCals = computed(() => historyStats.value?.total_calories ?? 0)
const mealCount = computed(() => meals.value.length)
const pctGoal   = computed(() => goal.value > 0 ? Math.round((totalCals.value / goal.value) * 100) : 0)

const maxCal = computed(() => {
  const vals = weekDays.value.map(d => d.total_calories)
  return Math.max(...vals, goal.value, 1)
})

function mealTag(timeStr: string): string {
  const h = parseInt(timeStr.split(':')[0], 10)
  if (h < 10) return 'Sáng'
  if (h < 14) return 'Trưa'
  if (h < 17) return 'Phụ'
  return 'Tối'
}

function mealEmoji(name: string): string {
  const n = name.toLowerCase()
  if (n.includes('phở') || n.includes('bún') || n.includes('mì')) return '🍜'
  if (n.includes('cơm')) return '🍚'
  if (n.includes('gà')) return '🍗'
  if (n.includes('thịt') || n.includes('bò')) return '🥩'
  if (n.includes('cá')) return '🐟'
  if (n.includes('rau') || n.includes('salad')) return '🥗'
  if (n.includes('sinh tố') || n.includes('nước')) return '🥤'
  if (n.includes('bánh')) return '🍞'
  return '🍽'
}

async function selectDay(dateStr: string) {
  selectedDate.value = dateStr
  await fetchHistory(dateStr)
}

const swipedId = ref<number | null>(null)
function toggleSwipe(id: number) {
  swipedId.value = swipedId.value === id ? null : id
}

async function removeLog(id: number) {
  const ok = await deleteLog(id)
  if (ok) await fetchHistory(selectedDate.value)
  swipedId.value = null
}

onMounted(async () => {
  if (store.token) {
    await fetchHistory(selectedDate.value)
  }
})
</script>

<template>
  <div class="pb-4">
    <!-- Header -->
    <div class="px-5 pt-2 pb-3">
      <h1 class="text-[28px] font-bold text-black animate-fadeInUp" style="opacity:0">Lịch sử</h1>

      <div class="mt-3 bg-ios-gray5 rounded-[10px] p-1 flex animate-fadeInUp delay-1" style="opacity:0">
        <button
          v-for="tab in [{ key: 'food', label: '🍽 Ăn uống' }, { key: 'exercise', label: '🏋️ Tập luyện' }]"
          :key="tab.key"
          class="flex-1 py-1.5 rounded-[8px] text-[14px] font-semibold transition-all"
          :class="activeTab === tab.key ? 'bg-white text-black shadow-sm' : 'text-ios-gray'"
          @click="activeTab = tab.key as 'food' | 'exercise'"
        >{{ tab.label }}</button>
      </div>
    </div>

    <!-- ── Food tab ── -->
    <template v-if="activeTab === 'food'">

      <!-- Auth gate -->
      <div v-if="!store.token" class="mx-5 bg-white rounded-[18px] p-6 text-center animate-fadeInUp" style="opacity:0">
        <p class="text-[32px] mb-2">📋</p>
        <p class="text-[17px] font-semibold text-black mb-1">Đăng nhập để xem lịch sử</p>
        <p class="text-[13px] text-ios-gray mb-4">Lịch sử bữa ăn được lưu khi bạn đăng nhập</p>
        <NuxtLink to="/auth/login" class="inline-block bg-ios-blue text-white text-[15px] font-semibold px-6 py-2.5 rounded-[12px] ios-press">
          Đăng nhập
        </NuxtLink>
      </div>

      <template v-else>
        <!-- 7-day strip -->
        <div class="px-5 mb-4 animate-fadeInUp delay-2" style="opacity:0">
          <div class="bg-white rounded-[18px] p-3">
            <div v-if="loading && !historyStats" class="flex justify-center py-3">
              <div class="w-5 h-5 rounded-full border-2 border-ios-blue border-t-transparent animate-spin"/>
            </div>
            <div v-else class="flex justify-between">
              <div
                v-for="day in weekDays"
                :key="day.date"
                class="flex flex-col items-center gap-1.5 cursor-pointer ios-press"
                @click="selectDay(day.date)"
              >
                <span class="text-[11px] text-ios-gray font-medium">{{ day.day_label }}</span>
                <div
                  class="w-9 h-9 rounded-full flex items-center justify-center font-semibold text-[14px] transition-all"
                  :class="selectedDate === day.date ? 'bg-ios-blue text-white' : 'text-black'"
                >{{ new Date(day.date + 'T12:00:00').getDate() }}</div>
                <div
                  class="w-1.5 h-1.5 rounded-full"
                  :class="day.total_calories > 0 ? (day.total_calories > goal ? 'bg-ios-red' : 'bg-ios-green') : 'bg-transparent'"
                />
              </div>
            </div>
          </div>
        </div>

        <!-- Bar chart -->
        <div v-if="historyStats" class="mx-5 mb-4 bg-white rounded-[18px] p-4 animate-fadeInUp delay-2" style="opacity:0">
          <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-3">
            {{ new Date(selectedDate + 'T12:00:00').toLocaleDateString('vi-VN', { month: 'long', year: 'numeric' }) }}
          </p>
          <div class="flex items-end gap-2 h-20">
            <div
              v-for="day in weekDays"
              :key="day.date"
              class="flex-1 flex flex-col items-center gap-1 cursor-pointer"
              @click="selectDay(day.date)"
            >
              <div class="w-full flex-1 flex items-end">
                <div
                  class="w-full rounded-t-[4px] transition-all duration-700"
                  :style="`height: ${day.total_calories > 0 ? Math.max(6, Math.round((day.total_calories / maxCal) * 100)) : 0}%; background: ${day.total_calories > goal ? '#FF3B30' : selectedDate === day.date ? '#007AFF' : '#C7C7CC'}`"
                />
              </div>
              <span class="text-[10px]" :class="selectedDate === day.date ? 'text-ios-blue font-semibold' : 'text-ios-gray'">
                {{ day.day_label }}
              </span>
            </div>
          </div>
          <div class="flex justify-between text-[12px] text-ios-gray mt-2">
            <span>TB: {{ weekDays.filter(d => d.total_calories > 0).length
              ? Math.round(weekDays.reduce((s, d) => s + d.total_calories, 0) / weekDays.filter(d => d.total_calories > 0).length)
              : 0 }} kcal</span>
            <span>Mục tiêu: {{ goal.toLocaleString() }} kcal</span>
          </div>
        </div>

        <!-- Summary card -->
        <div v-if="historyStats" class="mx-5 mb-4 bg-white rounded-[18px] px-5 py-4 shadow-sm animate-fadeInUp delay-3" style="opacity:0">
          <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-2">
            {{ new Date(selectedDate + 'T12:00:00').toLocaleDateString('vi-VN', { weekday: 'long', day: 'numeric', month: 'long' }) }}
          </p>
          <div class="flex items-end gap-1">
            <span class="text-[34px] font-bold text-black">{{ totalCals.toLocaleString() }}</span>
            <span class="text-[15px] text-ios-gray pb-1">kcal</span>
          </div>
          <div class="flex gap-4 mt-3 flex-wrap">
            <div>
              <p class="text-[11px] text-ios-gray">Mục tiêu</p>
              <p class="text-[14px] font-semibold text-black">{{ goal.toLocaleString() }}</p>
            </div>
            <div>
              <p class="text-[11px] text-ios-gray">Đạt</p>
              <p class="text-[14px] font-semibold" :class="pctGoal > 100 ? 'text-ios-red' : 'text-ios-green'">{{ pctGoal }}%</p>
            </div>
            <div>
              <p class="text-[11px] text-ios-gray">Số bữa</p>
              <p class="text-[14px] font-semibold text-black">{{ mealCount }}</p>
            </div>
            <div v-if="historyStats.total_protein > 0">
              <p class="text-[11px] text-ios-gray">Protein</p>
              <p class="text-[14px] font-semibold text-ios-blue">{{ historyStats.total_protein }}g</p>
            </div>
            <div v-if="historyStats.total_carbs > 0">
              <p class="text-[11px] text-ios-gray">Carbs</p>
              <p class="text-[14px] font-semibold text-ios-orange">{{ historyStats.total_carbs }}g</p>
            </div>
            <div v-if="historyStats.total_fat > 0">
              <p class="text-[11px] text-ios-gray">Chất béo</p>
              <p class="text-[14px] font-semibold text-ios-red">{{ historyStats.total_fat }}g</p>
            </div>
          </div>
        </div>

        <!-- Meals list -->
        <div class="px-5 animate-fadeInUp delay-4" style="opacity:0">
          <p class="text-[18px] font-semibold text-black mb-3">Bữa ăn trong ngày</p>

          <div v-if="loading" class="flex justify-center py-8">
            <div class="w-8 h-8 rounded-full border-2 border-ios-blue border-t-transparent animate-spin"/>
          </div>

          <div v-else-if="meals.length === 0" class="bg-white rounded-[18px] p-6 flex flex-col items-center gap-2 text-center">
            <CaloeyeCharacter mood="reminder" :size="72" />
            <p class="text-[15px] font-medium text-black">Chưa có bữa ăn nào</p>
            <p class="text-[13px] text-ios-gray">Quét món ăn để bắt đầu ghi lại</p>
          </div>

          <div v-else class="bg-white rounded-[18px] overflow-hidden shadow-sm">
            <div v-for="(meal, idx) in meals" :key="meal.id" class="relative overflow-hidden">
              <!-- Hidden delete button -->
              <div
                class="absolute right-0 top-0 bottom-0 w-20 bg-ios-red flex items-center justify-center"
                :class="swipedId === meal.id ? 'flex' : 'hidden'"
              >
                <button class="text-white text-[13px] font-semibold w-full h-full" @click.stop="removeLog(meal.id)">Xóa</button>
              </div>

              <div
                class="flex items-center gap-3 px-4 py-3.5 bg-white transition-transform"
                :class="swipedId === meal.id ? '-translate-x-20' : 'translate-x-0'"
                @click="toggleSwipe(meal.id)"
              >
                <div class="w-10 h-10 rounded-[10px] bg-ios-gray6 flex items-center justify-center text-xl flex-shrink-0">
                  {{ mealEmoji(meal.food_name) }}
                </div>
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-1.5 flex-wrap">
                    <p class="text-[15px] font-medium text-black truncate">{{ meal.food_name }}</p>
                    <span class="text-[10px] font-semibold text-ios-orange bg-ios-orange/10 rounded-full px-1.5 py-0.5 flex-shrink-0">
                      {{ mealTag(meal.logged_at) }}
                    </span>
                  </div>
                  <p class="text-[12px] text-ios-gray">{{ meal.logged_at }}{{ meal.serving ? ` · ${meal.serving}` : '' }}</p>
                </div>
                <div class="text-right flex-shrink-0">
                  <p class="text-[15px] font-semibold text-black">{{ meal.calories }}</p>
                  <p class="text-[11px] text-ios-gray">kcal</p>
                </div>
              </div>
              <div v-if="idx < meals.length - 1" class="ios-separator mx-4"/>
            </div>
          </div>

          <p v-if="meals.length" class="text-[12px] text-ios-gray text-center mt-3">Nhấn vào bữa ăn để hiện nút xóa</p>
        </div>
      </template>
    </template>

    <!-- Exercise tab -->
    <template v-else>
      <!-- Auth gate -->
      <div v-if="!store.token" class="mx-5 bg-white rounded-[18px] p-6 text-center animate-fadeInUp" style="opacity:0">
        <p class="text-[32px] mb-2">🏋️</p>
        <p class="text-[17px] font-semibold text-black mb-1">Đăng nhập để xem lịch sử</p>
        <p class="text-[13px] text-ios-gray mb-4">Lịch sử tập luyện được lưu khi bạn đăng nhập</p>
        <NuxtLink to="/auth/login" class="inline-block bg-ios-blue text-white text-[15px] font-semibold px-6 py-2.5 rounded-[12px] ios-press">
          Đăng nhập
        </NuxtLink>
      </div>

      <div v-else class="px-5 pt-2 animate-fadeInUp" style="opacity:0">
        <div class="bg-white rounded-[18px] overflow-hidden shadow-sm mb-4">
          <div class="px-5 py-4 border-b-hairline border-ios-gray5">
            <p class="text-[13px] text-ios-gray">Tổng hôm nay</p>
            <p class="text-[26px] font-bold text-ios-green">{{ todayBurned.toLocaleString('vi') }} <span class="text-[14px] font-normal text-ios-gray">kcal đốt cháy</span></p>
          </div>

          <!-- Loading -->
          <div v-if="actLoading && activities.length === 0" class="px-5 py-8 flex justify-center">
            <div class="w-7 h-7 rounded-full border-2 border-ios-green border-t-transparent animate-spin"/>
          </div>

          <!-- Empty -->
          <div v-else-if="activities.length === 0" class="px-5 py-6 flex flex-col items-center gap-3 text-center">
            <CaloeyeCharacter mood="exercise" :size="72" />
            <p class="text-[13px] text-ios-gray">Chưa có dữ liệu tập luyện</p>
          </div>

          <!-- Danh sách buổi tập -->
          <div v-else>
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
                    {{ fmtActDate(a.started_at) }} · {{ fmtActDuration(a.duration_seconds) }}<template v-if="fmtActDistance(a.distance_meters)"> · {{ fmtActDistance(a.distance_meters) }}</template>
                  </p>
                </div>
                <div class="text-right flex-shrink-0">
                  <p class="text-[15px] font-semibold text-ios-green">{{ a.calories ?? 0 }}</p>
                  <p class="text-[11px] text-ios-gray">kcal</p>
                </div>
              </div>
              <div v-if="idx < activities.length - 1" class="ios-separator mx-4"/>
            </div>

            <button
              v-if="hasMore"
              class="w-full py-2.5 text-[14px] text-ios-blue font-medium ios-press border-t-hairline border-ios-gray5"
              :disabled="actLoading"
              @click="fetchActivities(false)"
            >
              {{ actLoading ? 'Đang tải...' : 'Xem thêm' }}
            </button>
          </div>
        </div>

        <div class="bg-gradient-to-br from-ios-blue/5 to-ios-teal/5 border border-ios-blue/10 rounded-[18px] p-4">
          <p class="text-[15px] font-semibold text-black mb-1">Kết nối thiết bị</p>
          <p class="text-[13px] text-ios-gray mb-3">Đồng bộ dữ liệu từ Strava, Apple Health, Garmin...</p>
          <NuxtLink to="/profile?section=devices" class="inline-flex items-center gap-1.5 text-[14px] text-ios-blue font-semibold ios-press">
            Quản lý kết nối
            <svg viewBox="0 0 24 24" class="w-4 h-4" fill="currentColor">
              <path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/>
            </svg>
          </NuxtLink>
        </div>
      </div>
    </template>
  </div>
</template>
