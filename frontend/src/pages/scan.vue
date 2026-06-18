<script setup lang="ts">
definePageMeta({ layout: false, middleware: 'auth' })

const route = useRoute()
const isManual = computed(() => route.query.manual === 'true')

const mode = ref<'camera' | 'manual'>(isManual.value ? 'manual' : 'camera')
const manualText = ref('')
const capturing = ref(false)
const flashOn = ref(false)

async function capture() {
  capturing.value = true
  await new Promise(r => setTimeout(r, 600))
  capturing.value = false
  navigateTo('/result')
}

async function submitManual() {
  if (!manualText.value.trim()) return
  await navigateTo({ path: '/result', query: { food: manualText.value } })
}
</script>

<template>
  <div class="h-svh bg-black flex flex-col overflow-hidden select-none">
    <!-- Status bar (dark) -->
    <IosStatusBar :dark="true" />

    <!-- Camera/Manual toggle -->
    <div class="px-5 py-3 flex items-center justify-between">
      <button class="text-white ios-press" @click="navigateTo('/home')">
        <svg viewBox="0 0 24 24" class="w-6 h-6" fill="white">
          <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
        </svg>
      </button>

      <!-- Mode switcher -->
      <div class="bg-white/20 rounded-full p-1 flex">
        <button
          class="px-4 py-1.5 rounded-full text-[13px] font-semibold transition-all"
          :class="mode === 'camera' ? 'bg-white text-black' : 'text-white'"
          @click="mode = 'camera'"
        >Chụp ảnh</button>
        <button
          class="px-4 py-1.5 rounded-full text-[13px] font-semibold transition-all"
          :class="mode === 'manual' ? 'bg-white text-black' : 'text-white'"
          @click="mode = 'manual'"
        >Nhập tay</button>
      </div>

      <button
        class="text-white ios-press"
        :class="flashOn ? 'text-ios-yellow' : 'text-white'"
        @click="flashOn = !flashOn"
      >
        <svg viewBox="0 0 24 24" class="w-6 h-6" :fill="flashOn ? '#FFCC00' : 'white'">
          <path d="M7 2v11h3v9l7-12h-4l4-8z"/>
        </svg>
      </button>
    </div>

    <!-- Camera mode -->
    <template v-if="mode === 'camera'">
      <!-- Viewfinder area -->
      <div class="flex-1 relative mx-5 my-2">
        <!-- Simulated camera feed -->
        <div class="w-full h-full rounded-[20px] overflow-hidden bg-gray-900 relative">
          <!-- Gradient background simulating camera -->
          <div class="absolute inset-0 bg-gradient-to-b from-gray-800 via-gray-900 to-gray-800"/>

          <!-- Corner brackets (viewfinder guide) -->
          <div class="absolute inset-8">
            <!-- TL -->
            <div class="absolute top-0 left-0 w-8 h-8 border-t-[3px] border-l-[3px] border-white rounded-tl-[6px]"/>
            <!-- TR -->
            <div class="absolute top-0 right-0 w-8 h-8 border-t-[3px] border-r-[3px] border-white rounded-tr-[6px]"/>
            <!-- BL -->
            <div class="absolute bottom-0 left-0 w-8 h-8 border-b-[3px] border-l-[3px] border-white rounded-bl-[6px]"/>
            <!-- BR -->
            <div class="absolute bottom-0 right-0 w-8 h-8 border-b-[3px] border-r-[3px] border-white rounded-br-[6px]"/>

            <!-- Scan line -->
            <div class="absolute left-0 right-0 h-[2px] scan-line">
              <div class="w-full h-full bg-gradient-to-r from-transparent via-ios-blue to-transparent opacity-80"/>
            </div>
          </div>

          <!-- Character hint -->
          <div class="absolute bottom-5 left-4 flex items-end gap-3 animate-fadeInUp" style="opacity:0;animation-delay:0.5s">
            <CaloeyeCharacter
              :mood="capturing ? 'excited' : 'thinking'"
              :size="58"
              :message="capturing ? 'Đang phân tích...' : 'Đưa món ăn vào khung!'"
              bubble-dir="right"
            />
          </div>

          <!-- Capture flash overlay -->
          <div
            v-if="capturing"
            class="absolute inset-0 bg-white animate-fadeIn rounded-[20px]"
            style="animation-duration: 0.15s"
          />
        </div>
      </div>

      <!-- Controls row -->
      <div class="flex items-center justify-around px-10 py-5">
        <!-- Gallery -->
        <button class="w-12 h-12 rounded-[12px] bg-white/15 flex items-center justify-center ios-press">
          <svg viewBox="0 0 24 24" class="w-6 h-6" fill="white">
            <path d="M22 16V4c0-1.1-.9-2-2-2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2zm-11-4l2.03 2.71L16 11l4 5H8l3-4zM2 6v14c0 1.1.9 2 2 2h14v-2H4V6H2z"/>
          </svg>
        </button>

        <!-- Capture button -->
        <button
          class="w-[76px] h-[76px] rounded-full border-4 border-white flex items-center justify-center ios-press"
          :class="capturing ? 'scale-90' : ''"
          style="transition: transform 0.1s ease"
          @click="capture"
        >
          <div class="w-[60px] h-[60px] rounded-full bg-white"/>
        </button>

        <!-- Switch camera -->
        <button class="w-12 h-12 rounded-[12px] bg-white/15 flex items-center justify-center ios-press">
          <svg viewBox="0 0 24 24" class="w-6 h-6" fill="white">
            <path d="M20 5h-3.17L15 3H9L7.17 5H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm-5 11.5V14H9v2.5L5.5 13 9 9.5V12h6V9.5l3.5 3.5-3.5 3.5z"/>
          </svg>
        </button>
      </div>
    </template>

    <!-- Manual input mode -->
    <template v-else>
      <div class="flex-1 flex flex-col px-5 pt-4 animate-slideUpSheet">
        <div class="bg-white/10 rounded-[20px] p-5 flex-1 flex flex-col">
          <h3 class="text-white text-[17px] font-semibold mb-2">Mô tả món ăn</h3>
          <p class="text-white/60 text-[13px] mb-4 leading-relaxed">
            Nhập tên hoặc mô tả chi tiết để AI tính toán lượng calo chính xác nhất.
          </p>
          <textarea
            v-model="manualText"
            placeholder="Ví dụ: 1 bát phở bò lớn khoảng 500ml, thêm bánh quẩy và giá trụng..."
            rows="6"
            class="flex-1 bg-transparent text-white text-[15px] placeholder-white/30 outline-none resize-none leading-relaxed"
          />

          <!-- Suggestions -->
          <div class="flex flex-wrap gap-2 mt-4">
            <button
              v-for="s in ['Phở bò', 'Cơm tấm', 'Bún bò', 'Bánh mì', 'Bánh xèo']"
              :key="s"
              class="bg-white/10 rounded-full px-3 py-1.5 text-white text-[12px] ios-press"
              @click="manualText = s"
            >{{ s }}</button>
          </div>
        </div>

        <!-- Analyze button -->
        <button
          class="mt-4 mb-6 w-full h-[52px] rounded-[14px] font-semibold text-[17px] ios-press flex items-center justify-center"
          :class="manualText.trim() ? 'bg-calor-green text-white' : 'bg-white/20 text-white/50'"
          :disabled="!manualText.trim()"
          @click="submitManual"
        >
          <svg viewBox="0 0 24 24" class="w-5 h-5 mr-2" fill="currentColor">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/>
          </svg>
          Phân tích ngay
        </button>
      </div>
    </template>
  </div>
</template>
