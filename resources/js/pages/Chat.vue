<script setup lang="ts">
import CaloeyeCharacter from '@/components/caloeye/Character.vue'
import GuestGateModal from '@/components/common/GuestGateModal.vue'
import { useChat } from '@/composables/useChat'
import { useGuestQuota } from '@/composables/useGuestQuota'
import { useAuthStore } from '@/stores/auth'
import type { ChatMessage } from '@/types/chat'

const auth = useAuthStore()
const { streaming, send } = useChat()
const { canUse, increment } = useGuestQuota()
const gateOpen = ref(false)

const inputText = ref('')
const isTyping = ref(false)
const messagesEnd = ref<HTMLElement | null>(null)
const textareaRef = ref<HTMLTextAreaElement | null>(null)

// Auto-grow ô nhập theo nội dung, tối đa 5 dòng rồi mới cuộn (tránh vỡ layout)
const MAX_LINES = 5
const LINE_HEIGHT = 20 // px, khớp class leading-5

function autoResize() {
  const el = textareaRef.value
  if (!el) return
  el.style.height = 'auto'
  const maxH = LINE_HEIGHT * MAX_LINES
  el.style.height = `${Math.min(el.scrollHeight, maxH)}px`
  el.style.overflowY = el.scrollHeight > maxH ? 'auto' : 'hidden'
}

watch(inputText, () => nextTick(autoResize))

function nowTime() {
  return new Date().toLocaleTimeString('vi', { hour: '2-digit', minute: '2-digit' })
}

const firstName = computed(() => {
  const name = auth.user?.name?.trim()
  if (!name) return 'bạn'
  return name.split(' ').pop() || name
})

const messages = ref<ChatMessage[]>([
  {
    id: 1, role: 'ai',
    text: `Xin chào ${firstName.value}! 👋 Mình là trợ lý dinh dưỡng của CaloEye. Mình có thể gợi ý kế hoạch ăn uống & tập luyện cho ngày mai hoặc cả tháng này dựa trên dữ liệu bạn đã ghi. Bạn muốn bắt đầu từ đâu?`,
    time: nowTime(),
  },
])

const suggestions = [
  'Lên kế hoạch ăn uống cho ngày mai',
  'Gợi ý lịch tập tháng này',
  'Tối nay nên ăn gì với lượng calo còn lại?',
  'Tôi nên ăn bao nhiêu protein mỗi ngày?',
]

async function sendMessage() {
  const text = inputText.value.trim()
  if (!text || streaming.value) return

  // Giới hạn lượt tư vấn cho khách
  if (!canUse('chat')) {
    gateOpen.value = true
    return
  }
  increment('chat')

  messages.value.push({ id: Date.now(), role: 'user', text, time: nowTime() })
  inputText.value = ''
  scrollToBottom()

  // Lịch sử gửi lên (bỏ id/time, chỉ role + text) — gồm cả tin vừa nhập
  const history = messages.value.map(m => ({ role: m.role, text: m.text }))

  // Placeholder cho phản hồi AI sẽ stream dần
  const aiMsg: ChatMessage = { id: Date.now() + 1, role: 'ai', text: '', time: nowTime() }
  isTyping.value = true
  let aiIndex = -1
  let pending = ''          // text đã nhận từ server nhưng chưa "gõ" ra màn hình
  let streamDone = false

  // Typewriter: nhả buffer ra từng ít ký tự một → hiệu ứng gõ chữ mượt,
  // không phụ thuộc kích thước chunk server trả (Gemini hay trả nguyên cụm/câu).
  // Buffer càng nhiều thì rút càng nhanh để bám kịp, không bị trễ.
  const typing = new Promise<void>((resolve) => {
    const step = () => {
      if (aiIndex !== -1 && pending) {
        const n = Math.max(1, Math.round(pending.length / 6))
        messages.value[aiIndex].text += pending.slice(0, n)
        pending = pending.slice(n)
        scrollToBottom()
      }
      if (streamDone && !pending) { resolve(); return }
      setTimeout(step, 16)
    }
    step()
  })

  await send(history, (delta) => {
    if (isTyping.value) {
      isTyping.value = false
      messages.value.push(aiMsg)
      aiIndex = messages.value.length - 1
    }
    pending += delta
  })

  streamDone = true
  await typing

  isTyping.value = false
  if (aiIndex === -1) {
    aiMsg.text = 'Xin lỗi, mình chưa thể phản hồi lúc này. Bạn thử lại nhé! 🙏'
    messages.value.push(aiMsg)
  }
  await nextTick()
  scrollToBottom()
}

function useSuggestion(s: string) {
  inputText.value = s
  sendMessage()
}

function scrollToBottom() {
  nextTick(() => messagesEnd.value?.scrollIntoView({ behavior: 'smooth' }))
}

// Câu hỏi mồi khi đi từ Home (vd: "Lên kế hoạch ăn uống cho ngày mai")
const route = useRoute()
onMounted(() => {
  const ask = route.query.ask
  if (typeof ask === 'string' && ask.trim()) {
    inputText.value = ask.trim()
    sendMessage()
  }
})
</script>

<template>
  <div class="flex flex-col h-full">
    <!-- Header -->
    <div class="px-5 pt-2 pb-3 flex items-center gap-3">
      <CaloeyeCharacter mood="wave" :size="44" />
      <div>
        <h1 class="text-[17px] font-semibold text-black">CaloEye AI</h1>
        <div class="flex items-center gap-1">
          <div class="w-1.5 h-1.5 rounded-full bg-ios-green"/>
          <span class="text-[12px] text-ios-gray">Trực tuyến</span>
        </div>
      </div>
    </div>

    <!-- Messages area -->
    <div class="flex-1 overflow-y-auto px-4 py-2 space-y-3">
      <div
        v-for="msg in messages"
        :key="msg.id"
        class="flex animate-fadeInUp"
        :class="msg.role === 'user' ? 'justify-end' : 'justify-start'"
        style="opacity:0"
      >
        <!-- AI avatar -->
        <div v-if="msg.role === 'ai'" class="mr-2 mt-auto mb-1 flex-shrink-0">
          <CaloeyeCharacter mood="normal" :size="32" />
        </div>

        <div class="max-w-[78%]">
          <div
            class="px-3.5 py-2.5 rounded-[18px] text-[14px] leading-relaxed whitespace-pre-wrap"
            :class="msg.role === 'user'
              ? 'bg-ios-blue text-white rounded-br-[6px]'
              : 'bg-white text-black rounded-bl-[6px] shadow-sm'"
          >{{ msg.text }}</div>
          <p class="text-[10px] text-ios-gray mt-1" :class="msg.role === 'user' ? 'text-right' : 'text-left'">
            {{ msg.time }}
          </p>
        </div>
      </div>

      <!-- Typing indicator — giữ avatar như tin AI thường (mood normal, cùng SVG đã cache),
           chỉ bong bóng chat hiển thị loading, không reload icon nhân vật -->
      <div v-if="isTyping" class="flex items-end gap-2 animate-fadeIn">
        <div class="mr-0 mt-auto mb-1 flex-shrink-0">
          <CaloeyeCharacter mood="normal" :size="32" />
        </div>
        <div class="bg-white rounded-[18px] rounded-bl-[6px] px-4 py-3 shadow-sm flex gap-1.5 items-center">
          <div v-for="i in 3" :key="i" class="w-2 h-2 rounded-full bg-ios-gray3" :class="`typing-dot-${i}`"/>
        </div>
      </div>

      <div ref="messagesEnd"/>
    </div>

    <!-- Suggestions (shown when no user messages yet or after AI) -->
    <div
      v-if="messages.length <= 1"
      class="px-4 py-2 flex gap-2 overflow-x-auto scrollbar-hide"
    >
      <button
        v-for="s in suggestions"
        :key="s"
        class="flex-shrink-0 bg-white border border-ios-gray5 rounded-full px-3 py-1.5 text-[12px] text-ios-blue font-medium ios-press whitespace-nowrap"
        @click="useSuggestion(s)"
      >{{ s }}</button>
    </div>

    <!-- Input bar -->
    <div class="px-4 py-3 bg-[#F2F2F7] border-t-hairline border-ios-gray5">
      <div class="flex items-end gap-2">
        <div class="flex-1 bg-white rounded-[22px] border border-ios-gray5 px-4 py-2.5 flex items-end gap-2 min-h-[44px]">
          <textarea
            ref="textareaRef"
            v-model="inputText"
            :disabled="streaming"
            placeholder="Hỏi về dinh dưỡng, kế hoạch ăn/tập..."
            rows="1"
            class="flex-1 bg-transparent text-[15px] text-black placeholder-ios-gray3 outline-none resize-none leading-5 disabled:opacity-60"
            @keydown.enter.exact.prevent="sendMessage"
          />
        </div>
        <button
          class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 ios-press transition-colors"
          :class="inputText.trim() && !streaming ? 'bg-ios-blue' : 'bg-ios-gray5'"
          :disabled="!inputText.trim() || streaming"
          @click="sendMessage"
        >
          <svg viewBox="0 0 24 24" class="w-5 h-5" :fill="inputText.trim() ? 'white' : '#8E8E93'">
            <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
          </svg>
        </button>
      </div>
    </div>

    <GuestGateModal v-model:open="gateOpen" feature="tư vấn AI" />
  </div>
</template>
