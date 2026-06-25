<script setup lang="ts">
import { apiFetch } from '@/utils/api'
import { goWithAuth } from '@/utils/deeplink'

const props = defineProps<{ open: boolean }>()
const emit  = defineEmits<{ 'update:open': [v: boolean] }>()

interface NotifLog {
  id: number
  type: 'morning' | 'midday' | 'evening' | 'reengagement'
  title: string
  body: string
  url: string | null
  read_at: string | null
  created_at: string
}

const logs    = ref<NotifLog[]>([])
const loading = ref(false)

const typeIcon: Record<string, string> = {
  morning:       '☀️',
  midday:        '🍱',
  evening:       '🌙',
  reengagement:  '📧',
}

const unreadCount = computed(() => logs.value.filter(l => !l.read_at).length)

async function fetchHistory() {
  loading.value = true
  try {
    logs.value = await apiFetch<NotifLog[]>('/notifications/history')
  } catch {
    logs.value = []
  } finally {
    loading.value = false
  }
}

async function openItem(log: NotifLog) {
  // Đánh dấu đã đọc (optimistic) rồi điều hướng + đóng panel
  if (!log.read_at) {
    log.read_at = new Date().toISOString()
    apiFetch(`/notifications/${log.id}/read`, { method: 'PATCH' }).catch(() => {})
  }
  emit('update:open', false)
  goWithAuth(log.url ?? '/home')
}

async function markAllRead() {
  if (unreadCount.value === 0) return
  await apiFetch('/notifications/read-all', { method: 'PATCH' }).catch(() => {})
  logs.value = logs.value.map(l => ({ ...l, read_at: l.read_at ?? new Date().toISOString() }))
  emit('update:open', false)   // badge sẽ reset ở parent
}

function timeAgo(iso: string): string {
  const diff = (Date.now() - new Date(iso).getTime()) / 1000
  if (diff < 60)    return 'Vừa xong'
  if (diff < 3600)  return `${Math.floor(diff / 60)} phút trước`
  if (diff < 86400) return `${Math.floor(diff / 3600)} giờ trước`
  return `${Math.floor(diff / 86400)} ngày trước`
}

watch(() => props.open, (v) => { if (v) fetchHistory() })
</script>

<template>
  <Teleport to="body">
    <!-- Backdrop -->
    <Transition name="fade">
      <div
        v-if="open"
        class="fixed inset-0 bg-black/40 z-40 backdrop-blur-[2px]"
        @click="emit('update:open', false)"
      />
    </Transition>

    <!-- Panel -->
    <Transition name="slide-up">
      <div
        v-if="open"
        class="fixed bottom-0 left-0 right-0 z-50 bg-white rounded-t-[28px] max-h-[80svh] flex flex-col shadow-xl"
      >
        <!-- Handle + header -->
        <div class="flex-none pt-3 pb-2 px-5">
          <div class="w-10 h-1 bg-ios-gray3 rounded-full mx-auto mb-4" />
          <div class="flex items-center justify-between">
            <h2 class="text-[18px] font-bold text-black">Thông báo</h2>
            <div class="flex items-center gap-3">
              <button
                v-if="unreadCount > 0"
                class="text-[13px] text-ios-blue font-medium"
                @click="markAllRead"
              >
                Đọc tất cả
              </button>
              <RouterLink
                to="/settings/notifications"
                class="text-[13px] text-ios-gray"
                @click="emit('update:open', false)"
              >
                Cài đặt
              </RouterLink>
            </div>
          </div>
        </div>

        <div class="ios-separator mx-5" />

        <!-- List -->
        <div class="flex-1 overflow-y-auto overscroll-contain">
          <!-- Loading -->
          <div v-if="loading" class="py-12 flex justify-center">
            <div class="w-6 h-6 rounded-full border-2 border-calor-green border-t-transparent animate-spin" />
          </div>

          <!-- Empty -->
          <div v-else-if="logs.length === 0" class="py-14 flex flex-col items-center gap-3 text-center px-8">
            <span class="text-5xl">🔔</span>
            <p class="text-[15px] font-semibold text-black">Chưa có thông báo nào</p>
            <p class="text-[13px] text-ios-gray leading-relaxed">Bật thông báo để nhận nhắc nhở bữa ăn hàng ngày từ CaloEye nhé!</p>
            <RouterLink
              to="/settings/notifications"
              class="mt-1 text-[14px] text-ios-blue font-medium"
              @click="emit('update:open', false)"
            >
              Bật thông báo →
            </RouterLink>
          </div>

          <!-- Items -->
          <div v-else>
            <div
              v-for="(log, idx) in logs"
              :key="log.id"
            >
              <button
                type="button"
                class="w-full text-left flex items-start gap-3 px-5 py-3.5 active:bg-ios-gray5 transition-colors"
                :class="!log.read_at ? 'bg-ios-blue/[0.04]' : ''"
                @click="openItem(log)"
              >
                <!-- Icon -->
                <div class="w-10 h-10 rounded-[12px] flex items-center justify-center text-xl flex-shrink-0 mt-0.5"
                  :class="{
                    'bg-ios-yellow/20': log.type === 'morning',
                    'bg-ios-orange/15': log.type === 'midday',
                    'bg-ios-purple/15': log.type === 'evening',
                    'bg-ios-blue/10':   log.type === 'reengagement',
                  }"
                >
                  {{ typeIcon[log.type] ?? '🔔' }}
                </div>

                <!-- Content -->
                <div class="flex-1 min-w-0">
                  <div class="flex items-start justify-between gap-2">
                    <p class="text-[14px] font-semibold text-black leading-snug" :class="!log.read_at ? 'font-bold' : ''">
                      {{ log.title }}
                    </p>
                    <span class="text-[11px] text-ios-gray flex-shrink-0 mt-0.5">{{ timeAgo(log.created_at) }}</span>
                  </div>
                  <p class="text-[13px] text-ios-gray mt-0.5 leading-relaxed">{{ log.body }}</p>
                </div>

                <!-- Unread dot -->
                <div v-if="!log.read_at" class="w-2 h-2 rounded-full bg-ios-blue flex-shrink-0 mt-2" />
              </button>
              <div v-if="idx < logs.length - 1" class="ios-separator ml-[72px] mr-5" />
            </div>
          </div>
        </div>

        <!-- Safe area bottom -->
        <div class="flex-none" style="height: env(safe-area-inset-bottom)" />
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease }
.fade-enter-from, .fade-leave-to       { opacity: 0 }

.slide-up-enter-active { transition: transform 0.3s cubic-bezier(0.32, 0.72, 0, 1) }
.slide-up-leave-active { transition: transform 0.25s ease-in }
.slide-up-enter-from, .slide-up-leave-to { transform: translateY(100%) }
</style>
