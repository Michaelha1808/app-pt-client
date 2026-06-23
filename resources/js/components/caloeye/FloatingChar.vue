<script setup lang="ts">
import CaloeyeCharacter from '@/components/caloeye/Character.vue'

const route = useRoute()
const router = useRouter()
const isOpen = ref(false)
const isIn = ref(false)

// Drag
const CHAR_SIZE = 88
const charEl = ref<HTMLElement | null>(null)
const dragged = ref(false) // true once user has dragged from default position
const pos = ref({ x: 0, y: 0 })
const isDragging = ref(false)
const hasMoved = ref(false)
let dragStart = { x: 0, y: 0, posX: 0, posY: 0 }

onMounted(() => setTimeout(() => { isIn.value = true }, 1000))

function clamp(val: number, min: number, max: number) {
  return Math.max(min, Math.min(max, val))
}

function onPointerDown(e: PointerEvent) {
  isDragging.value = true
  hasMoved.value = false
  // First drag: grab current rendered position from DOM
  if (!dragged.value && charEl.value) {
    const rect = charEl.value.getBoundingClientRect()
    pos.value = { x: rect.left, y: rect.top }
    dragged.value = true
  }
  dragStart = { x: e.clientX, y: e.clientY, posX: pos.value.x, posY: pos.value.y }
  ;(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
}

function onPointerMove(e: PointerEvent) {
  if (!isDragging.value) return
  const dx = e.clientX - dragStart.x
  const dy = e.clientY - dragStart.y
  if (Math.abs(dx) > 4 || Math.abs(dy) > 4) hasMoved.value = true
  pos.value = {
    x: clamp(dragStart.posX + dx, 0, window.innerWidth - CHAR_SIZE),
    y: clamp(dragStart.posY + dy, 0, window.innerHeight - CHAR_SIZE),
  }
}

function onPointerUp() {
  isDragging.value = false
  if (!hasMoved.value) openPanel()
}

type Mood = 'normal' | 'happy' | 'celebrate' | 'thinking' | 'warning' | 'wave' | 'excited' | 'idle'

interface Action { icon: string; label: string; to: string }
interface Ctx { mood: Mood; title: string; message: string; actions: Action[] }

const contexts: Record<string, Ctx> = {
  '/home': {
    mood: 'wave',
    title: 'Xin chào! 👋',
    message: 'Hôm nay đang làm tốt đấy! Nhớ uống đủ nước và ăn đúng giờ để duy trì sức khỏe nhé.',
    actions: [
      { icon: '📸', label: 'Chụp món ăn', to: '/scan' },
      { icon: '💬', label: 'Tư vấn AI', to: '/chat' },
      { icon: '📊', label: 'Lịch sử', to: '/history' },
    ],
  },
  '/scan': {
    mood: 'thinking',
    title: 'Mẹo chụp ảnh 📷',
    message: 'Đặt món dưới ánh sáng tốt, chụp thẳng từ trên xuống. AI nhận diện chính xác hơn khi nhìn rõ toàn bộ món ăn!',
    actions: [
      { icon: '✏️', label: 'Nhập tay', to: '/scan?manual=true' },
      { icon: '🏠', label: 'Trang chủ', to: '/home' },
    ],
  },
  '/result': {
    mood: 'celebrate',
    title: 'Phân tích xong! 🎉',
    message: 'Kiểm tra lại thông tin calo và dinh dưỡng. Nhấn xác nhận để lưu vào nhật ký ngay nhé!',
    actions: [
      { icon: '💬', label: 'Hỏi AI thêm', to: '/chat' },
      { icon: '📊', label: 'Xem nhật ký', to: '/history' },
    ],
  },
  '/history': {
    mood: 'normal',
    title: 'Nhật ký sức khỏe 📈',
    message: 'Theo dõi xu hướng calo theo tuần để nhận biết thói quen ăn uống và điều chỉnh phù hợp!',
    actions: [
      { icon: '📸', label: 'Thêm bữa ăn', to: '/scan' },
      { icon: '👤', label: 'Mục tiêu', to: '/profile' },
    ],
  },
  '/chat': {
    mood: 'excited',
    title: 'AVO trợ lý AI 🤖',
    message: 'Hỏi tôi về thực đơn, lịch tập, hay bất kỳ điều gì về dinh dưỡng. Tôi luôn sẵn sàng!',
    actions: [
      { icon: '🥗', label: 'Gợi ý thực đơn', to: '/chat' },
      { icon: '📊', label: 'Xem lịch sử', to: '/history' },
    ],
  },
  '/profile': {
    mood: 'happy',
    title: 'Hồ sơ của bạn 👤',
    message: 'Cập nhật thông tin chính xác giúp AVO tư vấn tốt hơn. Kết nối thiết bị wearable để theo dõi tự động!',
    actions: [
      { icon: '🎯', label: 'Đặt mục tiêu', to: '/profile' },
      { icon: '💬', label: 'Hỏi AI', to: '/chat' },
    ],
  },
}

const ctx = computed<Ctx>(() => {
  const path = route.path
  const key = Object.keys(contexts).find(k => path === k || path.startsWith(k + '/')) ?? '/home'
  return contexts[key]
})

const floatMood = computed<Mood>(() => isOpen.value ? 'wave' : ctx.value.mood)

function openPanel() { isOpen.value = true }
function closePanel() { isOpen.value = false }

function goTo(to: string) {
  closePanel()
  router.push(to)
}
</script>

<template>
  <!-- Backdrop -->
  <Transition name="backdrop">
    <div
      v-if="isOpen"
      class="fixed inset-0 z-40"
      style="background: rgba(0,0,0,0.35); backdrop-filter: blur(4px)"
      @click="closePanel"
    />
  </Transition>

  <!-- Help panel (bottom sheet) -->
  <Transition name="sheet">
    <div
      v-if="isOpen"
      class="fixed bottom-0 left-1/2 -translate-x-1/2 w-full max-w-[430px] z-50 bg-white rounded-t-[28px] px-6 pt-3 pb-10 shadow-2xl"
    >
      <!-- Handle -->
      <div class="w-10 h-1 bg-ios-gray4 rounded-full mx-auto mb-5"/>

      <!-- Character + message -->
      <div class="flex items-end gap-4 mb-5">
        <CaloeyeCharacter :mood="ctx.mood" :size="88" />
        <div class="flex-1 bg-calor-light rounded-[18px] rounded-bl-[6px] px-4 py-3">
          <p class="text-[14px] font-semibold text-calor-deep mb-1">{{ ctx.title }}</p>
          <p class="text-[13px] text-calor-dark leading-relaxed">{{ ctx.message }}</p>
        </div>
      </div>

      <!-- Action buttons -->
      <div class="grid grid-cols-3 gap-2.5">
        <button
          v-for="a in ctx.actions"
          :key="a.label"
          class="flex flex-col items-center gap-1.5 bg-calor-bg border border-calor-mint/40 rounded-[16px] py-3.5 ios-press"
          @click="goTo(a.to)"
        >
          <span class="text-[22px]">{{ a.icon }}</span>
          <span class="text-[11px] font-semibold text-calor-deep text-center leading-tight">{{ a.label }}</span>
        </button>
      </div>

      <!-- Close -->
      <button
        class="absolute top-4 right-5 w-8 h-8 rounded-full bg-ios-gray5 flex items-center justify-center ios-press"
        @click="closePanel"
      >
        <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#8E8E93">
          <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
        </svg>
      </button>
    </div>
  </Transition>

  <!-- Floating button -->
  <Transition name="float-in">
    <div
      v-if="isIn && !isOpen && route.path !== '/scan'"
      ref="charEl"
      class="fixed z-40 select-none"
      :style="dragged
        ? { left: pos.x + 'px', top: pos.y + 'px', cursor: isDragging ? 'grabbing' : 'grab', touchAction: 'none' }
        : { right: '4px', bottom: '88px', cursor: 'grab', touchAction: 'none' }"
      @pointerdown="onPointerDown"
      @pointermove="onPointerMove"
      @pointerup="onPointerUp"
    >
      <!-- Glow ring -->
      <div class="absolute inset-0 rounded-full bg-calor-green animate-glow-pulse" style="margin: -8px"/>
      <!-- Character -->
      <div class="relative" :class="isDragging ? '' : 'char-idle'">
        <img
          :src="'/logo/AVO-mascot-nobg.png'"
          class="w-[72px] h-[72px] object-contain select-none drop-shadow-lg"
          draggable="false"
          alt="AVO"
        />
      </div>
      <!-- Notification dot -->
      <div class="absolute top-0 right-0 w-3 h-3 rounded-full bg-ios-orange border-2 border-white"/>
    </div>
  </Transition>
</template>

<style scoped>
/* Backdrop */
.backdrop-enter-active, .backdrop-leave-active { transition: opacity 0.25s ease; }
.backdrop-enter-from, .backdrop-leave-to { opacity: 0; }

/* Bottom sheet */
.sheet-enter-active { transition: transform 0.38s cubic-bezier(0.32, 0.72, 0, 1); }
.sheet-leave-active { transition: transform 0.28s ease-in; }
.sheet-enter-from, .sheet-leave-to { transform: translateX(-50%) translateY(100%); }

/* Float in from right */
.float-in-enter-active { transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1); }
.float-in-leave-active { transition: all 0.2s ease-in; }
.float-in-enter-from   { opacity: 0; transform: translateX(80px) scale(0.6); }
.float-in-leave-to     { opacity: 0; transform: scale(0.7); }
</style>
