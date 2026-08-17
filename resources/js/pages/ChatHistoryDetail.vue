<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'
import CaloeyeCharacter from '@/components/caloeye/Character.vue'
import { useChatHistory } from '@/composables/useChatHistory'
import { useToast } from '@/composables/useToast'
import type { ChatConversationDetail } from '@/types/chat'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const { getConversation } = useChatHistory()

const conversation = ref<ChatConversationDetail | null>(null)
const loading = ref(true)

function fmtTime(iso: string): string {
  return new Date(iso).toLocaleTimeString('vi', { hour: '2-digit', minute: '2-digit' })
}

// Render markdown tối giản mà AI hay dùng — cùng logic với Chat.vue
function formatMessage(text: string): string {
  const escaped = text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
  return escaped
    .replace(/\*\*([^*]+?)\*\*/g, '<strong>$1</strong>')
    .replace(/(^|[^*])\*([^*\n]+?)\*(?!\*)/g, '$1<em>$2</em>')
}

onMounted(async () => {
  const id = Number(route.params.id)
  try {
    conversation.value = await getConversation(id)
  } catch {
    toast.error('Không tìm thấy cuộc trò chuyện này')
    router.replace('/chat/history')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="flex flex-col h-full">
    <!-- Header -->
    <div class="flex items-center gap-3 px-4 pt-2 pb-4 flex-shrink-0">
      <button class="ios-press p-1 -ml-1" @click="router.back()">
        <svg viewBox="0 0 24 24" class="w-6 h-6" style="fill:var(--color-calor-green)">
          <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
        </svg>
      </button>
      <h1 class="text-[17px] font-semibold text-black truncate">{{ conversation?.title || 'Cuộc trò chuyện' }}</h1>
    </div>

    <div v-if="loading" class="flex-1 flex items-center justify-center">
      <div class="w-8 h-8 border-2 border-ios-gray5 border-t-ios-blue rounded-full animate-spin"/>
    </div>

    <div v-else-if="conversation" class="flex-1 overflow-y-auto px-4 py-2 space-y-3">
      <p class="text-center text-[11px] text-ios-gray mb-1">Chỉ xem lại — không thể tiếp tục nhắn vào cuộc trò chuyện này</p>

      <div
        v-for="(m, idx) in conversation.messages"
        :key="idx"
        class="flex"
        :class="m.role === 'user' ? 'justify-end' : 'justify-start'"
      >
        <div v-if="m.role === 'ai'" class="mr-2 mt-auto mb-1 flex-shrink-0">
          <CaloeyeCharacter mood="normal" :size="32" />
        </div>

        <div class="max-w-[78%]">
          <div
            class="px-3.5 py-2.5 rounded-[18px] text-[14px] leading-relaxed whitespace-pre-wrap [&_strong]:font-semibold"
            :class="m.role === 'user'
              ? 'bg-ios-blue text-white rounded-br-[6px]'
              : 'bg-white text-black rounded-bl-[6px] shadow-sm'"
            v-html="formatMessage(m.text)"
          />
          <p class="text-[10px] text-ios-gray mt-1" :class="m.role === 'user' ? 'text-right' : 'text-left'">
            {{ fmtTime(m.created_at) }}
          </p>
        </div>
      </div>
    </div>
  </div>
</template>
