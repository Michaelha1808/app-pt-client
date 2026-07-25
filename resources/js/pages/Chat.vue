<script setup lang="ts">
import CaloeyeCharacter from '@/components/caloeye/Character.vue'
import GuestGateModal from '@/components/common/GuestGateModal.vue'
import { useChat } from '@/composables/useChat'
import { useGuestQuota } from '@/composables/useGuestQuota'
import { usePublicConfig } from '@/composables/usePublicConfig'
import { useAuthStore } from '@/stores/auth'
import { navigateTo } from '@/utils/navigate'
import type { ChatAction, ChatMessage } from '@/types/chat'
import type { MemoryItem, MemoryConflict, PreferenceKind } from '@/types/preference'

// Nhãn hiển thị cho chip "đã ghi nhớ" theo loại sở thích
const MEMORY_KIND_LABEL: Record<PreferenceKind, string> = {
  allergy: 'Dị ứng',
  dislike: 'Không thích',
  like:    'Thích',
  diet:    'Chế độ ăn',
  habit:   'Thói quen',
}

const auth = useAuthStore()
const { streaming, send } = useChat()
// Flag admin: tắt chat AI → hiện empty-state, khoá ô nhập (vào thẳng URL vẫn thấy thông báo)
const { loadPublicConfig, flag } = usePublicConfig()
const chatEnabled = computed(() => flag(c => c.ai.chat_enabled))
const { canUse, increment } = useGuestQuota()
const { success, error: toastError } = useToast()
const gateOpen = ref(false)
const applyingPlan = ref(false)

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

// Render markdown tối giản mà AI hay dùng (đậm/nghiêng) — ESCAPE HTML trước nên an toàn với v-html.
// Xuống dòng do class whitespace-pre-wrap lo, không cần <br>.
function formatMessage(text: string): string {
  const escaped = text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
  return escaped
    .replace(/\*\*([^*]+?)\*\*/g, '<strong>$1</strong>')   // **đậm**
    .replace(/(^|[^*])\*([^*\n]+?)\*(?!\*)/g, '$1<em>$2</em>') // *nghiêng* (không đụng bullet ** )
}

const firstName = computed(() => {
  const name = auth.user?.name?.trim()
  if (!name) return 'bạn'
  return name.split(' ').pop() || name
})

// Lưu lịch sử chat theo NGÀY: cùng ngày thì khôi phục, sang ngày mới thì bắt đầu lại.
const STORAGE_KEY = 'caloeye:chat'

function todayStr() {
  return new Date().toLocaleDateString('en-CA') // YYYY-MM-DD theo giờ máy
}

function initialMessages(): ChatMessage[] {
  return [{
    id: 1, role: 'ai',
    text: `Xin chào ${firstName.value}! 👋 Mình là trợ lý dinh dưỡng của CaloEye. Mình có thể gợi ý kế hoạch ăn uống & tập luyện cho ngày mai hoặc cả tháng này dựa trên dữ liệu bạn đã ghi. Bạn muốn bắt đầu từ đâu?`,
    time: nowTime(),
  }]
}

function loadMessages(): ChatMessage[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw) {
      const saved = JSON.parse(raw) as { date: string; messages: ChatMessage[] }
      if (saved.date === todayStr() && Array.isArray(saved.messages) && saved.messages.length) {
        return saved.messages
      }
    }
  } catch { /* localStorage hỏng/không dùng được → dùng mặc định */ }
  return initialMessages()
}

const messages = ref<ChatMessage[]>(loadMessages())

function persist() {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ date: todayStr(), messages: messages.value }))
  } catch { /* hết quota → bỏ qua */ }
}

// Nút làm mới: xoá lịch sử hiện tại, quay về lời chào ban đầu.
function resetChat() {
  if (streaming.value) return
  if (messages.value.length > 1 && !confirm('Bắt đầu cuộc trò chuyện mới? Lịch sử hôm nay sẽ bị xoá.')) return
  messages.value = initialMessages()
  persist()
  scrollToBottom()
}

const suggestions = [
  'Lên kế hoạch ăn uống cho ngày mai',
  'Gợi ý lịch tập tháng này',
  'Tối nay nên ăn gì với lượng calo còn lại?',
  'Tôi nên ăn bao nhiêu protein mỗi ngày?',
]

async function sendMessage() {
  const text = inputText.value.trim()
  if (!text || streaming.value || !chatEnabled.value) return

  // Giới hạn lượt tư vấn cho khách
  if (!canUse('chat')) {
    gateOpen.value = true
    return
  }
  increment('chat')

  messages.value.push({ id: Date.now(), role: 'user', text, time: nowTime() })
  inputText.value = ''
  persist()
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

  await send(
    history,
    (delta) => {
      if (isTyping.value) {
        isTyping.value = false
        messages.value.push(aiMsg)
        aiIndex = messages.value.length - 1
      }
      pending += delta
    },
    (items: MemoryItem[], conflicts: MemoryConflict[]) => {
      // AI vừa ghi nhớ sở thích / phát hiện xung đột → gắn vào tin nhắn AI
      if (aiIndex !== -1 && (items.length || conflicts.length)) {
        if (items.length) messages.value[aiIndex].memory = items
        if (conflicts.length) messages.value[aiIndex].conflicts = conflicts
        persist()
      }
    },
    (actions: ChatAction[]) => {
      // Nút hành động gợi ý theo ngữ cảnh
      if (aiIndex !== -1 && actions.length) {
        messages.value[aiIndex].actions = actions
        persist()
      }
    },
  )

  streamDone = true
  await typing

  isTyping.value = false
  if (aiIndex === -1) {
    aiMsg.text = 'Xin lỗi, mình chưa thể phản hồi lúc này. Bạn thử lại nhé! 🙏'
    messages.value.push(aiMsg)
  }
  persist()
  await nextTick()
  scrollToBottom()
}

function useSuggestion(s: string) {
  inputText.value = s
  sendMessage()
}

// Hoàn tác một mục AI vừa ghi nhớ (xóa khỏi DB + bỏ chip)
async function undoMemory(msg: ChatMessage, id: number) {
  try {
    await apiFetch(`/preferences/${id}`, { method: 'DELETE' })
    msg.memory = msg.memory?.filter(m => m.id !== id)
    persist()
  } catch { /* lỗi mạng → giữ nguyên chip */ }
}

// Xử lý xung đột: đổi "thái độ" (accept) hoặc giữ nguyên
async function resolveConflict(msg: ChatMessage, c: MemoryConflict, accept: boolean) {
  if (accept) {
    try {
      const res = await apiFetch<{ preference: MemoryItem }>('/preferences', {
        method: 'POST',
        body:   { kind: c.suggested_kind, label: c.label },
      })
      msg.memory = [...(msg.memory ?? []), res.preference]
    } catch { /* bỏ qua, vẫn gỡ card xung đột bên dưới */ }
  }
  msg.conflicts = msg.conflicts?.filter(x => x.id !== c.id)
  persist()
}

// Bấm nút hành động sau khi AI tư vấn
async function handleAction(msg: ChatMessage, a: ChatAction) {
  if (a.action === 'navigate' && a.to) {
    navigateTo(a.to)
    return
  }
  if (a.action === 'prompt' && a.text) {
    inputText.value = a.text
    sendMessage()
    return
  }
  if (a.action === 'apply_plan') {
    if (applyingPlan.value) return
    applyingPlan.value = true
    try {
      const history = messages.value.map(m => ({ role: m.role, text: m.text }))
      await apiFetch('/chat/apply-plan', { method: 'POST', body: { messages: history } })
      msg.planApplied = true
      msg.actions = msg.actions?.filter(x => x.action !== 'apply_plan')
      persist()
      success('Đã thiết lập kế hoạch hôm nay 🎯')
    } catch (e: any) {
      toastError(e?.data?.message ?? 'Không thể thiết lập kế hoạch. Thử lại nhé.')
    } finally {
      applyingPlan.value = false
    }
  }
}

function scrollToBottom() {
  nextTick(() => messagesEnd.value?.scrollIntoView({ behavior: 'smooth' }))
}

// Câu hỏi mồi khi đi từ Home (vd: "Lên kế hoạch ăn uống cho ngày mai")
const route = useRoute()
onMounted(() => {
  loadPublicConfig()
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

      <!-- Làm mới: bắt đầu cuộc trò chuyện mới -->
      <button
        class="ml-auto w-9 h-9 rounded-full bg-white border border-ios-gray5 flex items-center justify-center flex-shrink-0 ios-press disabled:opacity-40"
        :disabled="streaming"
        aria-label="Làm mới cuộc trò chuyện"
        @click="resetChat"
      >
        <svg viewBox="0 0 24 24" class="w-[18px] h-[18px]" fill="none" stroke="#7c9a70" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 12a9 9 0 1 0 3-6.7L3 8"/>
          <path d="M3 3v5h5"/>
        </svg>
      </button>
    </div>

    <!-- Empty-state khi admin tắt chat AI -->
    <div v-if="!chatEnabled" class="flex-1 flex flex-col items-center justify-center gap-3 px-10">
      <CaloeyeCharacter mood="idle" :size="72" />
      <p class="text-[15px] font-medium text-black text-center">Tính năng tư vấn AI đang tạm tắt</p>
      <p class="text-[13px] text-ios-gray text-center leading-relaxed">Vui lòng quay lại sau nhé!</p>
    </div>

    <!-- Messages area -->
    <div v-else class="flex-1 overflow-y-auto px-4 py-2 space-y-3">
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
            class="px-3.5 py-2.5 rounded-[18px] text-[14px] leading-relaxed whitespace-pre-wrap [&_strong]:font-semibold"
            :class="msg.role === 'user'
              ? 'bg-ios-blue text-white rounded-br-[6px]'
              : 'bg-white text-black rounded-bl-[6px] shadow-sm'"
            v-html="formatMessage(msg.text)"
          />

          <!-- Chip: sở thích AI vừa ghi nhớ (kèm hoàn tác) -->
          <div v-if="msg.role === 'ai' && msg.memory?.length" class="flex flex-wrap gap-1.5 mt-1.5">
            <span
              v-for="m in msg.memory"
              :key="m.id"
              class="inline-flex items-center gap-1 pl-2.5 pr-1 py-1 rounded-full bg-ios-green/10 text-ios-green text-[11px] font-medium"
            >
              🧠 Đã ghi nhớ: {{ MEMORY_KIND_LABEL[m.kind] }} — {{ m.label }}
              <button
                class="w-4 h-4 rounded-full bg-ios-green/15 flex items-center justify-center ios-press"
                title="Hoàn tác"
                @click="undoMemory(msg, m.id)"
              >
                <svg viewBox="0 0 24 24" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">
                  <path d="M6 6l12 12M18 6L6 18"/>
                </svg>
              </button>
            </span>
          </div>

          <!-- Card: xung đột sở thích cần xác nhận -->
          <div
            v-for="c in (msg.role === 'ai' ? msg.conflicts ?? [] : [])"
            :key="c.id"
            class="mt-1.5 px-3 py-2 rounded-[12px] bg-ios-orange/10 text-[12px]"
          >
            <p class="text-black/80 mb-1.5">
              Trước đây bạn đánh dấu <b>{{ c.label }}</b> là “{{ MEMORY_KIND_LABEL[c.current_kind] }}”.
              Đổi thành “{{ MEMORY_KIND_LABEL[c.suggested_kind] }}”?
            </p>
            <div class="flex gap-2">
              <button
                class="px-3 py-1 rounded-full bg-ios-blue text-white text-[11px] font-semibold ios-press"
                @click="resolveConflict(msg, c, true)"
              >Đổi</button>
              <button
                class="px-3 py-1 rounded-full bg-black/5 text-ios-gray text-[11px] font-semibold ios-press"
                @click="resolveConflict(msg, c, false)"
              >Giữ nguyên</button>
            </div>
          </div>

          <!-- Nút hành động gợi ý sau tư vấn -->
          <div v-if="msg.role === 'ai' && msg.actions?.length" class="flex flex-wrap gap-1.5 mt-2">
            <button
              v-for="a in msg.actions"
              :key="a.id"
              class="px-3 py-1.5 rounded-full text-[12px] font-semibold ios-press disabled:opacity-50"
              :class="a.action === 'apply_plan' ? 'bg-ios-blue text-white' : 'bg-ios-blue/10 text-ios-blue'"
              :disabled="a.action === 'apply_plan' && applyingPlan"
              @click="handleAction(msg, a)"
            >{{ a.action === 'apply_plan' && applyingPlan ? 'Đang thiết lập...' : a.label }}</button>
          </div>
          <p v-if="msg.role === 'ai' && msg.planApplied" class="mt-1.5 text-[11px] text-calor-green font-medium">
            ✓ Đã thêm vào Nhiệm vụ hôm nay
          </p>

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
      v-if="chatEnabled && messages.length <= 1"
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
            :disabled="streaming || !chatEnabled"
            :placeholder="chatEnabled ? 'Hỏi về dinh dưỡng, kế hoạch ăn/tập...' : 'Tính năng đang tạm tắt'"
            rows="1"
            class="flex-1 bg-transparent text-[15px] text-black placeholder-ios-gray3 outline-none resize-none leading-5 disabled:opacity-60"
            @keydown.enter.exact.prevent="sendMessage"
          />
        </div>
        <button
          class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 ios-press transition-colors"
          :class="inputText.trim() && !streaming && chatEnabled ? 'bg-ios-blue' : 'bg-ios-gray5'"
          :disabled="!inputText.trim() || streaming || !chatEnabled"
          @click="sendMessage"
        >
          <svg viewBox="0 0 24 24" class="w-5 h-5" :fill="inputText.trim() ? 'white' : '#8a9a7d'">
            <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
          </svg>
        </button>
      </div>
    </div>

    <GuestGateModal v-model:open="gateOpen" feature="tư vấn AI" />
  </div>
</template>
