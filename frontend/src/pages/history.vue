<script setup lang="ts">
definePageMeta({ layout: 'app', middleware: 'auth-strict' })

const activeTab = ref<'food' | 'exercise'>('food')
const activeFilter = ref<'day' | 'week' | 'month' | 'quarter'>('day')
const selectedDay = ref(14)

const days = [
  { d: 10, label: 'T2', cal: 1850 },
  { d: 11, label: 'T3', cal: 2100 },
  { d: 12, label: 'T4', cal: 1650 },
  { d: 13, label: 'T5', cal: 1920 },
  { d: 14, label: 'T6', cal: 1340 },
  { d: 15, label: 'T7', cal: 0 },
  { d: 16, label: 'CN', cal: 0 },
]

const meals = [
  { id: 1, name: 'Phở bò', cal: 450, time: '07:30', tag: 'Sáng', emoji: '🍜' },
  { id: 2, name: 'Cơm gà xối mỡ', cal: 680, time: '12:15', tag: 'Trưa', emoji: '🍗' },
  { id: 3, name: 'Sinh tố bơ', cal: 210, time: '15:30', tag: 'Phụ', emoji: '🥑' },
]

const exercises = [
  { id: 1, name: 'Chạy bộ', duration: '32 phút', cal: 280, icon: '🏃', source: 'Strava' },
  { id: 2, name: 'Đạp xe', duration: '45 phút', cal: 320, icon: '🚴', source: 'Garmin' },
]

const filterLabels = { day: 'Ngày', week: 'Tuần', month: 'Tháng', quarter: 'Quý' }
const maxCal = 2100

const weekStats = [
  { day: 'T2', p: 93 }, { day: 'T3', p: 100 }, { day: 'T4', p: 82 },
  { day: 'T5', p: 96 }, { day: 'T6', p: 67 }, { day: 'T7', p: 0 }, { day: 'CN', p: 0 },
]
</script>

<template>
  <div class="pb-4">
    <!-- Header -->
    <div class="px-5 pt-2 pb-3">
      <h1 class="text-[28px] font-bold text-black animate-fadeInUp" style="opacity:0">Lịch sử</h1>

      <!-- Tab switcher: Ăn uống / Tập luyện -->
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

    <template v-if="activeTab === 'food'">
      <!-- Period filter -->
      <div class="px-5 mb-4 animate-fadeInUp delay-2" style="opacity:0">
        <div class="flex gap-2">
          <button
            v-for="(label, key) in filterLabels"
            :key="key"
            class="px-4 py-1.5 rounded-full text-[13px] font-medium transition-all ios-press"
            :class="activeFilter === key ? 'bg-ios-blue text-white' : 'bg-white text-ios-gray'"
            @click="activeFilter = key as typeof activeFilter"
          >{{ label }}</button>
        </div>
      </div>

      <!-- Day strip (visible only in day mode) -->
      <div v-if="activeFilter === 'day'" class="px-5 mb-4 animate-fadeInUp delay-3" style="opacity:0">
        <div class="bg-white rounded-[18px] p-3">
          <div class="flex justify-between">
            <div
              v-for="day in days"
              :key="day.d"
              class="flex flex-col items-center gap-1.5 cursor-pointer ios-press"
              @click="selectedDay = day.d"
            >
              <span class="text-[11px] text-ios-gray font-medium">{{ day.label }}</span>
              <div
                class="w-9 h-9 rounded-full flex items-center justify-center font-semibold text-[14px] transition-all"
                :class="selectedDay === day.d
                  ? 'bg-ios-blue text-white'
                  : 'text-black'"
              >{{ day.d }}</div>
              <!-- Calorie bar indicator -->
              <div class="w-1.5 h-1.5 rounded-full" :class="day.cal > 0 ? (day.cal > 2000 ? 'bg-ios-red' : 'bg-ios-green') : 'bg-transparent'"/>
            </div>
          </div>
        </div>
      </div>

      <!-- Week bar chart (visible in week mode) -->
      <div v-if="activeFilter === 'week'" class="mx-5 mb-4 bg-white rounded-[18px] p-4 animate-fadeInUp delay-3" style="opacity:0">
        <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-3">Tháng 6, 2026</p>
        <div class="flex items-end gap-2 h-24">
          <div
            v-for="s in weekStats"
            :key="s.day"
            class="flex-1 flex flex-col items-center gap-1"
          >
            <div class="w-full flex-1 flex items-end">
              <div
                class="w-full rounded-t-[4px] transition-all duration-700"
                :style="`height: ${s.p}%; background: ${s.p > 95 ? '#FF3B30' : '#007AFF'}`"
              />
            </div>
            <span class="text-[10px] text-ios-gray">{{ s.day }}</span>
          </div>
        </div>
        <div class="flex justify-between text-[12px] text-ios-gray mt-2">
          <span>TB: 1,870 kcal</span>
          <span>Mục tiêu: 2,000 kcal</span>
        </div>
      </div>

      <!-- Summary card -->
      <div class="mx-5 mb-4 bg-white rounded-[18px] px-5 py-4 shadow-sm animate-fadeInUp delay-3" style="opacity:0">
        <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-2">
          {{ activeFilter === 'day' ? `Ngày ${selectedDay}/6` : activeFilter === 'week' ? 'Tuần này' : activeFilter === 'month' ? 'Tháng này' : 'Quý này' }}
        </p>
        <div class="flex items-end gap-1">
          <span class="text-[34px] font-bold text-black">{{ activeFilter === 'day' ? '1,340' : activeFilter === 'week' ? '13,090' : '56,200' }}</span>
          <span class="text-[15px] text-ios-gray pb-1">kcal</span>
        </div>
        <div class="flex gap-4 mt-3">
          <div>
            <p class="text-[11px] text-ios-gray">Mục tiêu</p>
            <p class="text-[14px] font-semibold text-black">2,000</p>
          </div>
          <div>
            <p class="text-[11px] text-ios-gray">Đã ăn</p>
            <p class="text-[14px] font-semibold text-ios-green">{{ activeFilter === 'day' ? '67%' : '93%' }}</p>
          </div>
          <div>
            <p class="text-[11px] text-ios-gray">Số bữa</p>
            <p class="text-[14px] font-semibold text-black">{{ activeFilter === 'day' ? '3' : '21' }}</p>
          </div>
        </div>
      </div>

      <!-- Meals list -->
      <div class="px-5 animate-fadeInUp delay-4" style="opacity:0">
        <p class="text-[18px] font-semibold text-black mb-3">Bữa ăn trong ngày</p>
        <div class="bg-white rounded-[18px] overflow-hidden shadow-sm">
          <div v-for="(meal, idx) in meals" :key="meal.id">
            <div class="flex items-center gap-3 px-4 py-3.5">
              <div class="w-10 h-10 rounded-[10px] bg-ios-gray6 flex items-center justify-center text-xl flex-shrink-0">
                {{ meal.emoji }}
              </div>
              <div class="flex-1">
                <div class="flex items-center gap-1.5">
                  <p class="text-[15px] font-medium text-black">{{ meal.name }}</p>
                  <span class="text-[10px] font-semibold text-ios-orange bg-ios-orange/10 rounded-full px-1.5 py-0.5">{{ meal.tag }}</span>
                </div>
                <p class="text-[12px] text-ios-gray">{{ meal.time }}</p>
              </div>
              <div class="text-right">
                <p class="text-[15px] font-semibold text-black">{{ meal.cal }}</p>
                <p class="text-[11px] text-ios-gray">kcal</p>
              </div>
            </div>
            <div v-if="idx < meals.length - 1" class="ios-separator mx-4"/>
          </div>
        </div>
      </div>
    </template>

    <!-- Exercise tab -->
    <template v-else>
      <div class="px-5 pt-2 animate-fadeInUp" style="opacity:0">
        <div class="bg-white rounded-[18px] overflow-hidden shadow-sm mb-4">
          <div class="px-5 py-4 border-b-hairline border-ios-gray5">
            <p class="text-[13px] text-ios-gray">Tổng hôm nay</p>
            <p class="text-[26px] font-bold text-ios-green">600 <span class="text-[14px] font-normal text-ios-gray">kcal đốt cháy</span></p>
          </div>
          <div v-for="(ex, idx) in exercises" :key="ex.id">
            <div class="flex items-center gap-3 px-4 py-3.5">
              <div class="w-10 h-10 rounded-[10px] bg-ios-green/10 flex items-center justify-center text-xl">{{ ex.icon }}</div>
              <div class="flex-1">
                <p class="text-[15px] font-medium text-black">{{ ex.name }}</p>
                <div class="flex items-center gap-1.5 mt-0.5">
                  <span class="text-[12px] text-ios-gray">{{ ex.duration }}</span>
                  <span class="text-[10px] text-ios-blue bg-ios-blue/10 rounded-full px-1.5 py-0.5 font-semibold">{{ ex.source }}</span>
                </div>
              </div>
              <div class="text-right">
                <p class="text-[15px] font-semibold text-ios-green">{{ ex.cal }}</p>
                <p class="text-[11px] text-ios-gray">kcal</p>
              </div>
            </div>
            <div v-if="idx < exercises.length - 1" class="ios-separator mx-4"/>
          </div>
        </div>

        <!-- Connect devices CTA -->
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
