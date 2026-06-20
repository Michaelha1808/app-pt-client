<script setup lang="ts">
const route = useRoute()
const foodName = computed(() => (route.query.food as string) || 'Phở bò tái chín')
const isManual = computed(() => !!route.query.food)

// Simulated streaming AI response
const streamingText = ref('')
const streamDone = ref(false)
const confirmed = ref(false)

const fullAnalysis = `Phở bò tái chín là món ăn truyền thống Việt Nam rất giàu dinh dưỡng. Một tô phở cỡ vừa (~500ml) cung cấp lượng protein tốt từ thịt bò và đường bột từ bánh phở.

✅ **Điểm mạnh:** Giàu protein, ít chất béo bão hòa, cung cấp năng lượng ổn định.
⚠️ **Lưu ý:** Hàm lượng natri khá cao do nước dùng. Nên hạn chế uống hết nước.`

let streamInterval: ReturnType<typeof setInterval>

onMounted(() => {
  let i = 0
  streamInterval = setInterval(() => {
    if (i < fullAnalysis.length) {
      streamingText.value += fullAnalysis[i]
      i++
    } else {
      clearInterval(streamInterval)
      streamDone.value = true
    }
  }, 18)
})

onUnmounted(() => clearInterval(streamInterval))

async function confirmMeal() {
  confirmed.value = true
  await new Promise(r => setTimeout(r, 800))
  navigateTo('/home')
}

const calories = 450
const todayConsumed = 1340
const todayGoal = 2000
const afterEating = todayConsumed + calories
const macros = [
  { label: 'Protein', value: 28, unit: 'g', color: '#007AFF' },
  { label: 'Carbs', value: 56, unit: 'g', color: '#FF9500' },
  { label: 'Chất béo', value: 8, unit: 'g', color: '#FF2D55' },
  { label: 'Natri', value: 1200, unit: 'mg', color: '#8E8E93' },
]
</script>

<template>
  <div class="h-svh flex flex-col bg-[#F2F2F7] overflow-hidden">
    <div class="flex-none bg-[#F2F2F7]" style="height: env(safe-area-inset-top)" />

    <!-- Nav bar -->
    <div class="flex items-center px-5 py-3 bg-[#F2F2F7]">
      <button class="w-9 h-9 rounded-full bg-ios-gray5 flex items-center justify-center ios-press" @click="navigateTo('/scan')">
        <svg viewBox="0 0 24 24" class="w-5 h-5" fill="#18A874">
          <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
        </svg>
      </button>
      <h1 class="flex-1 text-[17px] font-semibold text-black text-center">Kết quả phân tích</h1>
      <div class="w-9"/>
    </div>

    <div class="flex-1 overflow-y-auto">
      <!-- Food image / manual icon -->
      <div class="mx-5 mb-4">
        <div
          class="w-full h-44 rounded-[20px] overflow-hidden animate-scaleIn"
          :class="isManual ? 'bg-gradient-to-br from-ios-orange/20 to-ios-yellow/20 flex items-center justify-center' : 'bg-gray-200'"
        >
          <div v-if="isManual" class="flex flex-col items-center gap-2">
            <span class="text-5xl">📝</span>
            <p class="text-[14px] text-ios-gray font-medium">Nhập từ văn bản</p>
          </div>
          <div v-else class="w-full h-full bg-gradient-to-br from-amber-100 to-orange-100 flex items-center justify-center">
            <span class="text-7xl">🍜</span>
          </div>
        </div>
      </div>

      <!-- Food name + calories -->
      <div class="mx-5 bg-white rounded-[18px] px-5 py-4 mb-4 shadow-sm animate-fadeInUp" style="opacity:0">
        <div class="flex items-start justify-between">
          <div class="flex-1">
            <p class="text-[13px] text-ios-gray uppercase tracking-wide font-semibold">Món ăn</p>
            <h2 class="text-[22px] font-bold text-black mt-0.5">{{ foodName }}</h2>
            <p class="text-[13px] text-ios-gray mt-0.5">1 khẩu phần · ~500g</p>
          </div>
          <div class="text-right">
            <p class="text-[36px] font-bold text-ios-blue leading-none">{{ calories }}</p>
            <p class="text-[13px] text-ios-gray">kcal</p>
          </div>
        </div>

        <!-- Macros grid -->
        <div class="grid grid-cols-4 gap-2 mt-4 pt-4 border-t-hairline border-ios-gray5">
          <div v-for="m in macros" :key="m.label" class="flex flex-col items-center">
            <div class="w-8 h-8 rounded-full flex items-center justify-center mb-1" :style="`background: ${m.color}18`">
              <div class="w-2.5 h-2.5 rounded-full" :style="`background: ${m.color}`"/>
            </div>
            <p class="text-[13px] font-semibold text-black">{{ m.value }}<span class="text-[10px] text-ios-gray">{{ m.unit }}</span></p>
            <p class="text-[10px] text-ios-gray text-center leading-tight">{{ m.label }}</p>
          </div>
        </div>
      </div>

      <!-- Impact analysis -->
      <div class="mx-5 bg-white rounded-[18px] px-5 py-4 mb-4 shadow-sm animate-fadeInUp delay-1" style="opacity:0">
        <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mb-3">Tác động hôm nay</p>

        <!-- Progress bar: before vs after -->
        <div class="mb-2">
          <div class="flex justify-between text-[12px] text-ios-gray mb-1.5">
            <span>Hiện tại: {{ todayConsumed }} kcal</span>
            <span>Mục tiêu: {{ todayGoal }} kcal</span>
          </div>
          <div class="h-3 bg-ios-gray6 rounded-full overflow-hidden relative">
            <!-- Before -->
            <div
              class="absolute top-0 left-0 h-full rounded-full bg-ios-green transition-all duration-500"
              :style="`width: ${(todayConsumed / todayGoal) * 100}%`"
            />
            <!-- After (overlay) -->
            <div
              class="absolute top-0 left-0 h-full rounded-l-full opacity-40 transition-all duration-700"
              :style="`width: ${Math.min((afterEating / todayGoal) * 100, 100)}%; background: ${afterEating > todayGoal ? '#FF3B30' : '#007AFF'}`"
            />
          </div>
        </div>

        <div
          class="mt-3 rounded-[12px] px-3 py-2.5 flex items-center gap-2"
          :class="afterEating > todayGoal ? 'bg-ios-red/8' : 'bg-ios-green/8'"
        >
          <span class="text-lg">{{ afterEating > todayGoal ? '⚠️' : '✅' }}</span>
          <p class="text-[13px]" :class="afterEating > todayGoal ? 'text-ios-red' : 'text-ios-green'">
            <span class="font-semibold">
              Sau khi ăn: {{ afterEating.toLocaleString('vi') }} kcal
            </span>
            — {{ afterEating > todayGoal
              ? `Vượt ${(afterEating - todayGoal).toLocaleString('vi')} kcal so với mục tiêu!`
              : `Còn ${(todayGoal - afterEating).toLocaleString('vi')} kcal cho bữa tối.` }}
          </p>
        </div>
      </div>

      <!-- Character reaction -->
      <div
        v-if="streamDone"
        class="mx-5 mb-4 rounded-[18px] px-4 py-4 flex items-center gap-4 animate-fadeInUp"
        :class="afterEating > todayGoal ? 'bg-ios-orange/10 border border-ios-orange/25' : 'bg-calor-light border border-calor-mint/50'"
        style="opacity:0"
      >
        <CaloeyeCharacter
          :mood="afterEating > todayGoal ? 'warning' : 'celebrate'"
          :size="64"
          :message="afterEating > todayGoal ? 'Cẩn thận nhé! 🔥' : 'Tuyệt vời! 🎉'"
          bubble-dir="right"
        />
        <p class="flex-1 text-[14px] leading-relaxed" :class="afterEating > todayGoal ? 'text-ios-orange' : 'text-calor-deep'">
          <template v-if="afterEating > todayGoal">
            Món này khá nhiều calo. Hãy cân nhắc vận động thêm để bù lại nhé!
          </template>
          <template v-else>
            Lựa chọn tuyệt vời! Bữa ăn này rất cân bằng và phù hợp với mục tiêu của bạn.
          </template>
        </p>
      </div>

      <!-- AI streaming advice -->
      <div class="mx-5 bg-white rounded-[18px] px-5 py-4 mb-4 shadow-sm animate-fadeInUp delay-2" style="opacity:0">
        <div class="flex items-center gap-2 mb-3">
          <div class="w-6 h-6 rounded-full bg-calor-green flex items-center justify-center">
            <svg viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="white">
              <path d="M12 2a5 5 0 110 10A5 5 0 0112 2zm0 12c5.33 0 8 2.67 8 4v2H4v-2c0-1.33 2.67-4 8-4z"/>
            </svg>
          </div>
          <p class="text-[13px] font-semibold text-black">Lời khuyên từ AI</p>
          <div v-if="!streamDone" class="ml-auto flex gap-1">
            <div v-for="i in 3" :key="i" class="w-1 h-1 rounded-full bg-ios-blue" :class="`typing-dot-${i}`"/>
          </div>
          <div v-else class="ml-auto">
            <span class="text-[11px] text-ios-green font-medium">✓ Hoàn tất</span>
          </div>
        </div>
        <div class="text-[14px] text-black/80 leading-relaxed whitespace-pre-wrap">{{ streamingText }}<span v-if="!streamDone" class="inline-block w-0.5 h-4 bg-ios-blue animate-pulse ml-0.5 align-middle"/></div>
      </div>

      <div class="h-4"/>
    </div>

    <!-- Action buttons -->
    <div class="px-5 py-4 bg-[#F2F2F7] border-t-hairline border-ios-gray5">
      <div class="flex gap-3">
        <button
          class="flex-1 h-[52px] rounded-[14px] bg-ios-gray5 text-black font-semibold text-[15px] ios-press"
          @click="navigateTo('/scan')"
        >Bỏ qua</button>
        <button
          class="flex-[2] h-[52px] rounded-[14px] text-white font-semibold text-[17px] ios-press flex items-center justify-center gap-2"
          :class="confirmed ? 'bg-ios-green' : 'bg-calor-green'"
          @click="confirmMeal"
        >
          <svg v-if="confirmed" viewBox="0 0 24 24" class="w-5 h-5 animate-scaleIn" fill="white">
            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
          </svg>
          <svg v-else viewBox="0 0 24 24" class="w-5 h-5" fill="white">
            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
          </svg>
          <span>{{ confirmed ? 'Đã lưu!' : 'Xác nhận & Lưu' }}</span>
        </button>
      </div>
    </div>
  </div>
</template>
