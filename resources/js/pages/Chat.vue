<script setup lang="ts">
interface Message {
  id: number
  role: 'user' | 'ai'
  text: string
  time: string
}

const inputText = ref('')
const isTyping = ref(false)
const messagesEnd = ref<HTMLElement | null>(null)

const messages = ref<Message[]>([
  {
    id: 1, role: 'ai',
    text: 'Xin chào Minh! 👋 Tôi là trợ lý AI của bạn. Hôm nay bạn đã ăn 1,340 kcal, còn 660 kcal cho bữa tối. Tôi có thể giúp gì cho bạn?',
    time: '09:00',
  },
  {
    id: 2, role: 'user',
    text: 'Tối nay nên ăn gì để đủ calo mà không bị béo?',
    time: '09:05',
  },
  {
    id: 3, role: 'ai',
    text: 'Với 660 kcal còn lại, bạn có thể chọn:\n\n🥗 **Salad gà nướng** (~350 kcal) + cơm gạo lứt nửa chén (~120 kcal)\n🐟 **Cá hồi nướng** (~300 kcal) + rau xào dầu oliu (~80 kcal)\n\nCả hai đều giàu protein giúp duy trì cơ bắp và no lâu. Bạn thích loại nào hơn?',
    time: '09:05',
  },
])

const suggestions = [
  'Tôi nên ăn bao nhiêu protein mỗi ngày?',
  'Bài tập nào phù hợp cho người mới?',
  'Phở có lành mạnh không?',
  'Cách tính BMR của tôi?',
]

const aiResponses = [
  'Dựa trên thông tin của bạn (nam, 70kg, 170cm), nhu cầu protein hàng ngày là khoảng **112-140g**. Bạn đã nạp được 82g hôm nay rồi, cố gắng thêm 30-58g nữa nhé! 💪',
  'Với người mới bắt đầu, tôi gợi ý:\n\n🏃 **Tuần 1-2:** Đi bộ nhanh 30 phút/ngày\n💪 **Tuần 3-4:** Thêm bài tập thể dục nhẹ tại nhà\n\nKhởi đầu từ từ sẽ giúp bạn duy trì lâu dài hơn!',
  'Phở bò trung bình **400-500 kcal**/tô, khá cân bằng! Điểm cộng: nhiều protein từ thịt, ít chất béo. Điểm trừ: hàm lượng natri cao (~1200mg). Nên ăn 3-4 lần/tuần là hợp lý. 🍜',
  `BMR của bạn (nam, 26 tuổi, 170cm, 70kg) theo công thức Mifflin-St Jeor:\n\n**BMR = 1,703 kcal/ngày**\n\nNhân với hệ số hoạt động (~1.55 nếu tập luyện vừa phải) = **2,640 kcal** để duy trì cân nặng.`,
]

let aiResponseIndex = 0

async function sendMessage() {
  const text = inputText.value.trim()
  if (!text) return

  messages.value.push({
    id: Date.now(), role: 'user', text,
    time: new Date().toLocaleTimeString('vi', { hour: '2-digit', minute: '2-digit' }),
  })
  inputText.value = ''
  scrollToBottom()

  isTyping.value = true
  await new Promise(r => setTimeout(r, 1400 + Math.random() * 800))
  isTyping.value = false

  messages.value.push({
    id: Date.now() + 1, role: 'ai',
    text: aiResponses[aiResponseIndex % aiResponses.length],
    time: new Date().toLocaleTimeString('vi', { hour: '2-digit', minute: '2-digit' }),
  })
  aiResponseIndex++
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
</script>

<template>
  <div class="flex flex-col h-full">
    <!-- Header -->
    <div class="px-5 pt-2 pb-3 flex items-center gap-3">
      <CaloeyeCharacter mood="happy" :size="44" />
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

      <!-- Typing indicator -->
      <div v-if="isTyping" class="flex items-end gap-2 animate-fadeIn">
        <div class="flex-shrink-0">
          <CaloeyeCharacter mood="thinking" :size="32" />
        </div>
        <div class="bg-white rounded-[18px] rounded-bl-[6px] px-4 py-3 shadow-sm flex gap-1.5 items-center">
          <div v-for="i in 3" :key="i" class="w-2 h-2 rounded-full bg-ios-gray3" :class="`typing-dot-${i}`"/>
        </div>
      </div>

      <div ref="messagesEnd"/>
    </div>

    <!-- Suggestions (shown when no user messages yet or after AI) -->
    <div
      v-if="messages.length <= 3"
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
            v-model="inputText"
            placeholder="Hỏi về dinh dưỡng, sức khỏe..."
            rows="1"
            class="flex-1 bg-transparent text-[15px] text-black placeholder-ios-gray3 outline-none resize-none max-h-24 leading-5"
            @keydown.enter.exact.prevent="sendMessage"
          />
        </div>
        <button
          class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 ios-press transition-colors"
          :class="inputText.trim() ? 'bg-ios-blue' : 'bg-ios-gray5'"
          :disabled="!inputText.trim()"
          @click="sendMessage"
        >
          <svg viewBox="0 0 24 24" class="w-5 h-5 rotate-90" :fill="inputText.trim() ? 'white' : '#8E8E93'">
            <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
          </svg>
        </button>
      </div>
    </div>
  </div>
</template>
