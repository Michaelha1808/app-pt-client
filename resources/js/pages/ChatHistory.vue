<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useChatHistory } from '@/composables/useChatHistory'
import { useToast } from '@/composables/useToast'
import { navigateTo } from '@/utils/navigate'

const router = useRouter()
const toast = useToast()
const { conversations, loading, loadingMore, hasMore, fetchConversations, fetchMore, deleteConversation } = useChatHistory()

function timeAgo(iso: string | null): string {
  if (!iso) return ''
  const diff = (Date.now() - new Date(iso).getTime()) / 1000
  if (diff < 60)    return 'Vừa xong'
  if (diff < 3600)  return `${Math.floor(diff / 60)} phút trước`
  if (diff < 86400) return `${Math.floor(diff / 3600)} giờ trước`
  if (diff < 172800) return 'Hôm qua'
  return `${Math.floor(diff / 86400)} ngày trước`
}

async function remove(id: number, event: Event) {
  event.stopPropagation()
  if (!confirm('Xoá cuộc trò chuyện này? Không thể hoàn tác.')) return
  try {
    await deleteConversation(id)
    toast.success('Đã xoá cuộc trò chuyện')
  } catch {
    toast.error('Không thể xoá. Thử lại nhé.')
  }
}

onMounted(fetchConversations)
</script>

<template>
  <div class="pb-24">
    <!-- Header -->
    <div class="flex items-center gap-3 px-4 pt-2 pb-4">
      <button class="ios-press p-1 -ml-1" @click="router.back()">
        <svg viewBox="0 0 24 24" class="w-6 h-6" style="fill:var(--color-calor-green)">
          <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
        </svg>
      </button>
      <h1 class="text-[17px] font-semibold text-black">Lịch sử trò chuyện</h1>
    </div>

    <div class="mx-4">
      <div v-if="loading" class="space-y-2">
        <div v-for="i in 4" :key="i" class="bg-white rounded-[16px] h-20 animate-pulse shadow-sm"/>
      </div>

      <div v-else-if="conversations.length === 0" class="bg-white rounded-[16px] px-4 py-10 flex flex-col items-center gap-2 text-center shadow-sm">
        <span class="text-4xl">💬</span>
        <p class="text-[14px] text-ios-gray">Chưa có cuộc trò chuyện nào được lưu</p>
        <button class="mt-1 px-4 py-2 bg-ios-blue text-white text-[14px] font-semibold rounded-[10px] ios-press" @click="navigateTo('/chat')">
          Bắt đầu trò chuyện
        </button>
      </div>

      <div v-else class="bg-white rounded-[16px] overflow-hidden shadow-sm">
        <div v-for="(c, idx) in conversations" :key="c.id">
          <!-- Div clickable (không dùng <button> ở đây) vì bên trong còn nút Xoá riêng —
               <button> lồng <button> là HTML không hợp lệ, khiến click/stopPropagation vỡ hành vi -->
          <div
            class="w-full flex items-center gap-3 px-4 py-3.5 text-left ios-press cursor-pointer"
            role="button"
            tabindex="0"
            @click="navigateTo(`/chat/history/${c.id}`)"
            @keydown.enter="navigateTo(`/chat/history/${c.id}`)"
          >
            <div class="w-10 h-10 rounded-[10px] bg-ios-gray6 flex items-center justify-center text-xl flex-shrink-0">💬</div>
            <div class="flex-1 min-w-0">
              <p class="text-[15px] font-medium text-black truncate">{{ c.title || 'Cuộc trò chuyện' }}</p>
              <p class="text-[12px] text-ios-gray mt-0.5 truncate">{{ c.preview }}</p>
            </div>
            <div class="text-right flex-shrink-0 flex flex-col items-end gap-1.5">
              <p class="text-[11px] text-ios-gray">{{ timeAgo(c.last_message_at) }}</p>
              <button class="ios-press p-1 -mr-1 text-ios-gray3" aria-label="Xoá cuộc trò chuyện" @click="remove(c.id, $event)">
                <svg viewBox="0 0 24 24" class="w-4 h-4" fill="currentColor"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
              </button>
            </div>
          </div>
          <div v-if="idx < conversations.length - 1" class="ios-separator mx-4"/>
        </div>
      </div>

      <button
        v-if="hasMore"
        class="w-full mt-3 py-2.5 text-[14px] text-ios-blue font-medium ios-press"
        :disabled="loadingMore"
        @click="fetchMore"
      >
        {{ loadingMore ? 'Đang tải...' : 'Xem thêm' }}
      </button>
    </div>
  </div>
</template>
