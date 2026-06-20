<script setup lang="ts">
import CaloeyeCharacter from '@/components/caloeye/Character.vue'

const route = useRoute()
const isManual = computed(() => route.query.manual === 'true')
const mode = ref<'camera' | 'manual'>(isManual.value ? 'manual' : 'camera')
const manualText = ref('')

// ── Camera state ──────────────────────────────────────────────────────────────
const videoEl = ref<HTMLVideoElement | null>(null)
const stream = ref<MediaStream | null>(null)
const facingMode = ref<'environment' | 'user'>('environment')
const capturing = ref(false)
const flashOn = ref(false)
const cameraError = ref<string | null>(null)
const switching = ref(false)
const galleryInput = ref<HTMLInputElement | null>(null)

async function startCamera() {
  cameraError.value = null
  try {
    if (stream.value) {
      stream.value.getTracks().forEach(t => t.stop())
      stream.value = null
    }
    const s = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: facingMode.value, width: { ideal: 1920 }, height: { ideal: 1080 } },
      audio: false,
    })
    stream.value = s
    if (videoEl.value) videoEl.value.srcObject = s
  } catch (e: any) {
    cameraError.value = e?.name === 'NotAllowedError' || e?.name === 'PermissionDeniedError'
      ? 'Vui lòng cấp quyền truy cập camera trong cài đặt trình duyệt'
      : 'Không thể mở camera. Hãy kiểm tra kết nối thiết bị.'
  }
}

async function switchCamera() {
  switching.value = true
  facingMode.value = facingMode.value === 'environment' ? 'user' : 'environment'
  await startCamera()
  switching.value = false
}

async function toggleFlash() {
  flashOn.value = !flashOn.value
  const track = stream.value?.getVideoTracks()[0]
  if (track) {
    try {
      await track.applyConstraints({ advanced: [{ torch: flashOn.value } as any] })
    } catch { flashOn.value = !flashOn.value }
  }
}

async function capture() {
  if (!videoEl.value || capturing.value) return
  capturing.value = true
  const canvas = document.createElement('canvas')
  canvas.width = videoEl.value.videoWidth || 1280
  canvas.height = videoEl.value.videoHeight || 720
  canvas.getContext('2d')?.drawImage(videoEl.value, 0, 0)
  sessionStorage.setItem('scan_image', canvas.toDataURL('image/jpeg', 0.85))
  await new Promise(r => setTimeout(r, 300))
  stream.value?.getTracks().forEach(t => t.stop())
  navigateTo('/result')
}

function pickFromGallery() { galleryInput.value?.click() }

function onGalleryPick(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  const reader = new FileReader()
  reader.onload = () => {
    sessionStorage.setItem('scan_image', reader.result as string)
    stream.value?.getTracks().forEach(t => t.stop())
    navigateTo('/result')
  }
  reader.readAsDataURL(file)
}

async function submitManual() {
  if (!manualText.value.trim()) return
  await navigateTo({ path: '/result', query: { food: manualText.value } })
}

watch([stream, videoEl], ([s, v]) => { if (s && v) v.srcObject = s })
watch(mode, (val) => {
  if (val === 'camera') startCamera()
  else stream.value?.getTracks().forEach(t => t.stop())
})

onMounted(() => { if (mode.value === 'camera') startCamera() })
onUnmounted(() => stream.value?.getTracks().forEach(t => t.stop()))
</script>

<template>
  <div class="fixed inset-0 bg-black overflow-hidden">
    <!-- Hidden gallery input -->
    <input ref="galleryInput" type="file" accept="image/*" class="hidden" @change="onGalleryPick" />

    <!-- ── Camera feed (full screen) ── -->
    <video
      v-if="mode === 'camera' && !cameraError"
      ref="videoEl"
      autoplay playsinline muted
      class="absolute inset-0 w-full h-full object-cover"
    />

    <!-- Camera permission error -->
    <div
      v-if="mode === 'camera' && cameraError"
      class="absolute inset-0 flex flex-col items-center justify-center gap-4 px-10"
    >
      <svg viewBox="0 0 24 24" class="w-16 h-16 opacity-30" fill="white">
        <path d="M12 15.2A3.2 3.2 0 018.8 12 3.2 3.2 0 0112 8.8a3.2 3.2 0 013.2 3.2A3.2 3.2 0 0112 15.2M9 2L7.17 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2h-3.17L15 2H9z"/>
      </svg>
      <p class="text-white/70 text-[15px] text-center leading-relaxed">{{ cameraError }}</p>
      <button class="bg-white/20 text-white px-6 py-3 rounded-[14px] text-[15px] font-semibold ios-press" @click="startCamera">
        Thử lại
      </button>
    </div>

    <!-- ── Top gradient overlay ── -->
    <div class="absolute top-0 inset-x-0 h-40 bg-gradient-to-b from-black/70 to-transparent pointer-events-none" />

    <!-- ── Bottom gradient overlay ── -->
    <div class="absolute bottom-0 inset-x-0 h-56 bg-gradient-to-t from-black/80 to-transparent pointer-events-none" />

    <!-- ── Top bar ── -->
    <div class="absolute top-0 inset-x-0" style="padding-top: env(safe-area-inset-top)">
      <div class="flex items-center justify-between px-5 py-3">
        <!-- Close -->
        <button class="w-9 h-9 rounded-full bg-black/30 flex items-center justify-center ios-press" @click="navigateTo('/home')">
          <svg viewBox="0 0 24 24" class="w-5 h-5" fill="white">
            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
          </svg>
        </button>

        <!-- Mode toggle -->
        <div class="bg-black/40 rounded-full p-1 flex backdrop-blur-sm">
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

        <!-- Flash -->
        <button class="w-9 h-9 rounded-full bg-black/30 flex items-center justify-center ios-press" @click="toggleFlash">
          <svg viewBox="0 0 24 24" class="w-5 h-5" :fill="flashOn ? '#FFCC00' : 'white'">
            <path d="M7 2v11h3v9l7-12h-4l4-8z"/>
          </svg>
        </button>
      </div>
    </div>

    <!-- ── Viewfinder brackets (camera mode) ── -->
    <div v-if="mode === 'camera' && !cameraError" class="absolute inset-0 flex items-center justify-center pointer-events-none">
      <div class="relative w-64 h-64">
        <div class="absolute top-0 left-0 w-10 h-10 border-t-[3px] border-l-[3px] border-white rounded-tl-[8px]"/>
        <div class="absolute top-0 right-0 w-10 h-10 border-t-[3px] border-r-[3px] border-white rounded-tr-[8px]"/>
        <div class="absolute bottom-0 left-0 w-10 h-10 border-b-[3px] border-l-[3px] border-white rounded-bl-[8px]"/>
        <div class="absolute bottom-0 right-0 w-10 h-10 border-b-[3px] border-r-[3px] border-white rounded-br-[8px]"/>
        <div class="absolute left-0 right-0 h-[2px] scan-line">
          <div class="w-full h-full bg-gradient-to-r from-transparent via-ios-blue to-transparent opacity-80"/>
        </div>
      </div>
    </div>

    <!-- ── AVO hint (camera mode) ── -->
    <div
      v-if="mode === 'camera' && !cameraError"
      class="absolute left-5 pointer-events-none animate-fadeInUp"
      style="bottom: 160px; opacity:0; animation-delay:0.8s"
    >
      <CaloeyeCharacter
        :mood="capturing ? 'excited' : 'thinking'"
        :size="52"
        :message="capturing ? 'Đang phân tích...' : 'Đưa món ăn vào khung!'"
        bubble-dir="right"
      />
    </div>

    <!-- ── Capture flash ── -->
    <div v-if="capturing" class="absolute inset-0 bg-white pointer-events-none animate-fadeIn" style="animation-duration:0.15s"/>

    <!-- ── Bottom controls (camera mode) ── -->
    <div
      v-if="mode === 'camera'"
      class="absolute bottom-0 inset-x-0 flex items-center justify-around px-10"
      style="padding-bottom: calc(env(safe-area-inset-bottom) + 24px); padding-top: 20px"
    >
      <!-- Gallery -->
      <button
        class="w-14 h-14 rounded-[16px] bg-white/20 backdrop-blur-sm flex items-center justify-center ios-press"
        @click="pickFromGallery"
      >
        <svg viewBox="0 0 24 24" class="w-6 h-6" fill="white">
          <path d="M22 16V4c0-1.1-.9-2-2-2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2zm-11-4l2.03 2.71L16 11l4 5H8l3-4zM2 6v14c0 1.1.9 2 2 2h14v-2H4V6H2z"/>
        </svg>
      </button>

      <!-- Capture -->
      <button
        class="w-[80px] h-[80px] rounded-full border-4 border-white flex items-center justify-center ios-press shadow-xl"
        :class="{ 'scale-90': capturing }"
        style="transition: transform 0.1s ease"
        @click="capture"
      >
        <div class="w-[64px] h-[64px] rounded-full" :class="capturing ? 'bg-white/60' : 'bg-white'"/>
      </button>

      <!-- Switch camera -->
      <button
        class="w-14 h-14 rounded-[16px] bg-white/20 backdrop-blur-sm flex items-center justify-center ios-press"
        :class="{ 'opacity-40': switching }"
        @click="switchCamera"
      >
        <svg viewBox="0 0 24 24" class="w-6 h-6" fill="white">
          <path d="M20 5h-3.17L15 3H9L7.17 5H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm-5 11.5V14H9v2.5L5.5 13 9 9.5V12h6V9.5l3.5 3.5-3.5 3.5z"/>
        </svg>
      </button>
    </div>

    <!-- ── Manual input panel (slides up over camera) ── -->
    <Transition
      enter-active-class="transition-transform duration-300 ease-out"
      enter-from-class="translate-y-full"
      enter-to-class="translate-y-0"
      leave-active-class="transition-transform duration-250 ease-in"
      leave-from-class="translate-y-0"
      leave-to-class="translate-y-full"
    >
      <div
        v-if="mode === 'manual'"
        class="absolute bottom-0 inset-x-0 bg-[#1c1c1e] rounded-t-[28px] px-5 pt-4"
        style="padding-bottom: calc(env(safe-area-inset-bottom) + 16px)"
      >
        <!-- Handle -->
        <div class="w-10 h-1 bg-white/20 rounded-full mx-auto mb-5"/>

        <h3 class="text-white text-[17px] font-semibold mb-2">Mô tả món ăn</h3>
        <p class="text-white/50 text-[13px] mb-4 leading-relaxed">Nhập tên hoặc mô tả chi tiết để AI tính toán lượng calo chính xác nhất.</p>

        <textarea
          v-model="manualText"
          placeholder="Ví dụ: 1 bát phở bò lớn khoảng 500ml, thêm bánh quẩy và giá trụng..."
          rows="5"
          class="w-full bg-white/10 text-white text-[15px] placeholder-white/30 outline-none resize-none leading-relaxed rounded-[16px] px-4 py-3"
        />

        <div class="flex flex-wrap gap-2 mt-3 mb-4">
          <button
            v-for="s in ['Phở bò', 'Cơm tấm', 'Bún bò', 'Bánh mì', 'Bánh xèo']"
            :key="s"
            class="bg-white/10 rounded-full px-3 py-1.5 text-white text-[12px] ios-press"
            @click="manualText = s"
          >{{ s }}</button>
        </div>

        <button
          class="w-full h-[52px] rounded-[14px] font-semibold text-[17px] ios-press flex items-center justify-center gap-2"
          :class="manualText.trim() ? 'bg-calor-green text-white' : 'bg-white/15 text-white/40'"
          :disabled="!manualText.trim()"
          @click="submitManual"
        >
          <svg viewBox="0 0 24 24" class="w-5 h-5" fill="currentColor">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/>
          </svg>
          Phân tích ngay
        </button>
      </div>
    </Transition>
  </div>
</template>
