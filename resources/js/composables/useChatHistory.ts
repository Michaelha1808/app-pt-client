import { ref } from 'vue'
import { apiFetch } from '@/utils/api'
import type { ChatConversationSummary, ChatConversationDetail } from '@/types/chat'

interface ConversationsPage {
  data: ChatConversationSummary[]
  meta: { current_page: number; per_page: number; total: number; last_page: number }
}

export function useChatHistory() {
  const conversations = ref<ChatConversationSummary[]>([])
  const loading   = ref(false)
  const loadingMore = ref(false)
  const hasMore   = ref(true)
  const page      = ref(1)

  async function fetchConversations(): Promise<void> {
    loading.value = true
    page.value = 1
    try {
      const res = await apiFetch<ConversationsPage>('/chat/conversations?page=1')
      conversations.value = res.data
      hasMore.value = res.meta.current_page < res.meta.last_page
    } finally {
      loading.value = false
    }
  }

  async function fetchMore(): Promise<void> {
    if (loadingMore.value || !hasMore.value) return
    loadingMore.value = true
    try {
      const next = page.value + 1
      const res = await apiFetch<ConversationsPage>(`/chat/conversations?page=${next}`)
      conversations.value.push(...res.data)
      page.value = next
      hasMore.value = res.meta.current_page < res.meta.last_page
    } finally {
      loadingMore.value = false
    }
  }

  async function getConversation(id: number): Promise<ChatConversationDetail> {
    return apiFetch<ChatConversationDetail>(`/chat/conversations/${id}`)
  }

  async function deleteConversation(id: number): Promise<void> {
    await apiFetch(`/chat/conversations/${id}`, { method: 'DELETE' })
    conversations.value = conversations.value.filter(c => c.id !== id)
  }

  return {
    conversations, loading, loadingMore, hasMore,
    fetchConversations, fetchMore, getConversation, deleteConversation,
  }
}
