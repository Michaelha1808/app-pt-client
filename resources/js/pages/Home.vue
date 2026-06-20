<script setup lang="ts">
import { useMealLog } from '@/composables/useMealLog'
import { useAuthStore } from '@/stores/auth'

const store = useAuthStore()
const { todayStats, loading, fetchTodayStats } = useMealLog()

const consumed = computed(() => todayStats.value?.total_calories ?? 0)
const goal     = computed(() => store.user?.calorie_goal ?? 2000)

const macros = computed(() => {
  const s = todayStats.value
  const proteinGoal = Math.round(goal.value * 0.3 / 4)
  const carbGoal    = Math.round(goal.value * 0.45 / 4)
  const fatGoal     = Math.round(goal.value * 0.25 / 9)
  return [
    { label: 'Protein',   value: s?.total_protein ?? 0, max: proteinGoal, unit: 'g', color: '#007AFF' },
    { label: 'Carbs',     value: s?.total_carbs   ?? 0, max: carbGoal,    unit: 'g', color: '#FF9500' },
    { label: 'Chất béo',  value: s?.total_fat     ?? 0, max: fatGoal,     unit: 'g', color: '#FF2D55' },
  ]
})

const meals = computed(() => todayStats.value?.meals ?? [])

const userName = computed(() => store.user?.name?.split(' ').at(-1) ?? 'bạn')
const userInitial = computed(() => store.user?.name?.[0]?.toUpperCase() ?? '?')

onMounted(() => {
  if (store.token) fetchTodayStats()
})
</script>

<template>
  <div class="pb-4">
    <!-- Page header -->
    <div class="px-5 pt-2 pb-3">
      <div class="flex items-center justify-between animate-fadeInUp" style="opacity:0">
        <div>
          <p class="text-[14px] text-ios-gray">{{ new Date().toLocaleDateString('vi-VN', { weekday: 'long', day: 'numeric', month: 'long' }) }}</p>
          <h1 class="text-[22px] font-bold text-black leading-tight mt-0.5">Xin chào, <span class="text-calor-green">{{ userName }}!</span></h1>
        </div>
        <NuxtLink to="/profile">
          <div class="w-11 h-11 rounded-full overflow-hidden bg-gradient-to-br from-calor-green to-calor-dark flex items-center justify-center">
            <img v-if="store.user?.avatar_url" :src="store.user.avatar_url" class="w-full h-full object-cover" />
            <span v-else class="text-white font-bold text-[15px]">{{ userInitial }}</span>
          </div>
        </NuxtLink>
      </div>
    </div>

    <!-- Character greeting card -->
    <div class="mx-5 mb-4 bg-gradient-to-r from-calor-light to-[#C8F0E2] rounded-[20px] px-4 py-4 flex items-center gap-4 animate-fadeInUp delay-1 shadow-sm shadow-calor-green/10" style="opacity:0">
      <CaloeyeCharacter
        :mood="consumed >= goal ? 'warning' : consumed >= goal * 0.8 ? 'normal' : 'happy'"
        :size="76"
      />
      <div class="flex-1 min-w-0">
        <p class="text-[15px] font-semibold text-calor-deep leading-snug">
          <template v-if="consumed >= goal">Bạn đã đạt mục tiêu calo!</template>
          <template v-else-if="consumed >= goal * 0.8">Sắp đạt mục tiêu rồi!</template>
          <template v-else>Hãy duy trì chế độ ăn lành mạnh nhé!</template>
        </p>
        <p class="text-[13px] text-calor-dark mt-1 leading-relaxed">
          <template v-if="consumed >= goal">Hãy vận động nhẹ để tiêu hao năng lượng 🏃</template>
          <template v-else>Còn <strong>{{ (goal - consumed).toLocaleString('vi') }} kcal</strong> cho hôm nay 🌿</template>
        </p>
      </div>
    </div>

    <!-- Calorie ring card -->
    <div class="mx-5 mb-4 bg-white rounded-[20px] px-5 py-6 shadow-sm animate-fadeInUp delay-1" style="opacity:0">
      <h2 class="text-[13px] font-semibold text-ios-gray uppercase tracking-wider mb-4">Calo hôm nay</h2>
      <div class="flex justify-center">
        <HomeCalorieRing :consumed="consumed" :goal="goal" />
      </div>
      <div v-if="loading" class="mt-3 flex justify-center">
        <div class="w-4 h-4 rounded-full border-2 border-calor-green border-t-transparent animate-spin"/>
      </div>
    </div>

    <!-- Macros card -->
    <div class="mx-5 mb-4 bg-white rounded-[20px] px-5 py-4 shadow-sm animate-fadeInUp delay-2" style="opacity:0">
      <h2 class="text-[13px] font-semibold text-ios-gray uppercase tracking-wider mb-4">Dưỡng chất</h2>
      <div class="flex flex-col gap-3">
        <div v-for="m in macros" :key="m.label" class="flex items-center gap-3">
          <span class="text-[13px] text-black font-medium w-16">{{ m.label }}</span>
          <div class="flex-1 h-2 bg-ios-gray6 rounded-full overflow-hidden">
            <div
              class="h-full rounded-full transition-all duration-1000"
              :style="`width: ${(m.value / m.max) * 100}%; background: ${m.color}`"
            />
          </div>
          <span class="text-[12px] text-ios-gray w-14 text-right">{{ m.value }}/{{ m.max }}{{ m.unit }}</span>
        </div>
      </div>
    </div>

    <!-- Quick actions -->
    <div class="px-5 mb-4 animate-fadeInUp delay-3" style="opacity:0">
      <h2 class="text-[13px] font-semibold text-ios-gray uppercase tracking-wider mb-3">Thêm nhanh</h2>
      <div class="grid grid-cols-3 gap-3">
        <NuxtLink
          to="/scan"
          class="bg-gradient-to-br from-ios-blue to-[#5AC8FA] rounded-[16px] p-4 flex flex-col items-center gap-2 ios-press shadow-md shadow-ios-blue/25"
        >
          <svg viewBox="0 0 24 24" class="w-7 h-7" fill="white">
            <path d="M4 4h3V2H2v5h2V4zm13-2v2h3v3h2V2h-5zm3 16h-3v2h5v-5h-2v3zM4 17H2v5h5v-2H4v-3zM15 9H9v6h6V9zm-2 4h-2v-2h2v2zm-7 0V9l1-1h6l1 1v4l-1 1H7l-1-1z"/>
          </svg>
          <span class="text-white text-[12px] font-semibold text-center">Chụp ảnh</span>
        </NuxtLink>

        <NuxtLink
          to="/scan?manual=true"
          class="bg-gradient-to-br from-ios-orange to-ios-yellow rounded-[16px] p-4 flex flex-col items-center gap-2 ios-press shadow-md shadow-ios-orange/25"
        >
          <svg viewBox="0 0 24 24" class="w-7 h-7" fill="white">
            <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>
          </svg>
          <span class="text-white text-[12px] font-semibold text-center">Nhập tay</span>
        </NuxtLink>

        <NuxtLink
          to="/chat"
          class="bg-gradient-to-br from-ios-purple to-ios-pink rounded-[16px] p-4 flex flex-col items-center gap-2 ios-press shadow-md shadow-ios-purple/25"
        >
          <svg viewBox="0 0 24 24" class="w-7 h-7" fill="white">
            <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/>
          </svg>
          <span class="text-white text-[12px] font-semibold text-center">Tư vấn AI</span>
        </NuxtLink>
      </div>
    </div>

    <!-- Today's meals -->
    <div class="px-5 animate-fadeInUp delay-4" style="opacity:0">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-[18px] font-semibold text-black">Bữa ăn hôm nay</h2>
        <NuxtLink to="/history" class="text-[14px] text-ios-blue font-medium">Xem tất cả</NuxtLink>
      </div>

      <div class="bg-white rounded-[18px] overflow-hidden shadow-sm">
        <div v-if="meals.length === 0 && !loading" class="px-4 py-6 flex flex-col items-center gap-2 text-center">
          <span class="text-3xl">🍽️</span>
          <p class="text-[14px] text-ios-gray">Chưa có bữa ăn nào hôm nay</p>
        </div>
        <div
          v-for="(meal, idx) in meals"
          :key="meal.id"
        >
          <div class="flex items-center gap-3 px-4 py-3.5">
            <!-- Icon -->
            <div class="w-10 h-10 rounded-[10px] bg-ios-gray6 flex items-center justify-center text-xl flex-shrink-0">
              🍽️
            </div>
            <!-- Info -->
            <div class="flex-1 min-w-0">
              <p class="text-[15px] font-medium text-black truncate">{{ meal.food_name }}</p>
              <p class="text-[12px] text-ios-gray mt-0.5">{{ meal.logged_at }}{{ meal.serving ? ` · ${meal.serving}` : '' }}</p>
            </div>
            <!-- Calories -->
            <div class="text-right">
              <p class="text-[15px] font-semibold text-black">{{ meal.calories }}</p>
              <p class="text-[11px] text-ios-gray">kcal</p>
            </div>
          </div>
          <div v-if="idx < meals.length - 1" class="ios-separator mx-4"/>
        </div>

        <!-- Add meal row -->
        <div class="ios-separator mx-4"/>
        <NuxtLink to="/scan" class="flex items-center gap-3 px-4 py-3.5 ios-press">
          <div class="w-10 h-10 rounded-[10px] bg-ios-blue/10 flex items-center justify-center">
            <svg viewBox="0 0 24 24" class="w-5 h-5" fill="#007AFF">
              <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
            </svg>
          </div>
          <span class="text-[15px] text-ios-blue font-medium">Thêm bữa ăn</span>
        </NuxtLink>
      </div>
    </div>

    <!-- AI suggestion -->
    <div class="mx-5 mt-4 bg-gradient-to-r from-ios-blue/5 to-ios-purple/5 border border-ios-blue/15 rounded-[18px] p-4 animate-fadeInUp delay-5" style="opacity:0">
      <div class="flex gap-3">
        <div class="w-8 h-8 rounded-full bg-ios-blue flex items-center justify-center flex-shrink-0 mt-0.5">
          <svg viewBox="0 0 24 24" class="w-4 h-4" fill="white">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/>
          </svg>
        </div>
        <div>
          <p class="text-[13px] font-semibold text-black">Gợi ý từ AI</p>
          <p class="text-[13px] text-ios-gray mt-0.5 leading-relaxed">
            Bạn còn <span class="text-black font-semibold">{{ (goal - consumed).toLocaleString('vi') }} kcal</span> cho bữa tối. Hãy thử <span class="text-ios-blue font-medium">salad gà</span> để đảm bảo đủ protein nhé!
          </p>
        </div>
      </div>
    </div>
  </div>
</template>
