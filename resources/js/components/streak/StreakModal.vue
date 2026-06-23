<script setup lang="ts">
import { ALL_MILESTONES, MILESTONE_META, type StreakData } from '@/composables/useStreak'

const props = defineProps<{
  open:    boolean
  streak:  StreakData | null
  loading: boolean
}>()

const emit = defineEmits<{
  'update:open': [boolean]
  useFreeze:     []
}>()

function close() { emit('update:open', false) }

const progressPercent = computed(() => {
  if (!props.streak) return 0
  const next = props.streak.next_milestone
  if (!next) return 100
  const prev = ALL_MILESTONES[ALL_MILESTONES.indexOf(next) - 1] ?? 0
  const span  = next - prev
  const done  = props.streak.current_streak - prev
  return Math.min(Math.round((done / span) * 100), 100)
})

const freezeSlots = computed(() => {
  const tokens = props.streak?.freeze_tokens ?? 0
  return [0, 1, 2].map(i => i < tokens)
})
</script>

<template>
  <Teleport to="body">
    <Transition name="sheet">
      <div v-if="open" class="fixed inset-0 z-50 flex items-end" @click.self="close">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="close" />

        <!-- Sheet -->
        <div class="relative w-full bg-white rounded-t-[28px] px-5 pt-3 pb-10 max-h-[85vh] overflow-y-auto">
          <!-- Drag handle -->
          <div class="w-10 h-1 bg-ios-gray4 rounded-full mx-auto mb-5" />

          <!-- Header -->
          <div class="text-center mb-6">
            <div class="text-[56px] leading-none mb-2">🥑</div>
            <div class="text-[42px] font-black text-calor-deep leading-none">
              {{ streak?.current_streak ?? 0 }}
            </div>
            <div class="text-[15px] text-ios-gray mt-1">ngày liên tiếp</div>
            <div v-if="(streak?.best_streak ?? 0) > 0" class="text-[13px] text-ios-gray3 mt-1">
              Kỷ lục cao nhất: <strong class="text-calor-deep">{{ streak!.best_streak }} ngày</strong>
            </div>
          </div>

          <!-- Progress bar đến milestone tiếp theo -->
          <div v-if="streak?.next_milestone" class="mb-6">
            <div class="flex items-center justify-between text-[13px] mb-2">
              <span class="text-ios-gray">Tiến độ</span>
              <span class="font-semibold text-calor-deep">
                {{ MILESTONE_META[streak.next_milestone].emoji }}
                {{ streak.next_milestone }} ngày — còn {{ streak.next_milestone - streak.current_streak }} ngày nữa
              </span>
            </div>
            <div class="h-2.5 bg-ios-gray6 rounded-full overflow-hidden">
              <div
                class="h-full bg-gradient-to-r from-calor-green to-calor-dark rounded-full transition-all duration-700"
                :style="`width: ${progressPercent}%`"
              />
            </div>
          </div>

          <!-- Freeze tokens -->
          <div class="bg-ios-gray6 rounded-[16px] p-4 mb-5">
            <div class="flex items-center justify-between mb-3">
              <div>
                <p class="text-[14px] font-semibold text-black">Freeze Token ❄️</p>
                <p class="text-[12px] text-ios-gray mt-0.5">Dùng để bảo vệ chuỗi khi lỡ 1 ngày</p>
              </div>
              <div class="flex gap-1.5">
                <span
                  v-for="(filled, i) in freezeSlots"
                  :key="i"
                  class="text-[22px]"
                  :class="filled ? 'opacity-100' : 'opacity-25 grayscale'"
                >❄️</span>
              </div>
            </div>

            <button
              v-if="streak?.can_use_freeze"
              class="w-full py-2.5 rounded-[12px] bg-ios-blue text-white text-[14px] font-semibold ios-press"
              @click="emit('useFreeze'); close()"
            >
              Dùng Freeze Token — cứu chuỗi!
            </button>
            <p v-else-if="(streak?.freeze_tokens ?? 0) === 0" class="text-[12px] text-ios-gray text-center">
              Không có token. Đạt chuỗi 7 ngày để nhận thêm.
            </p>
            <p v-else class="text-[12px] text-ios-gray text-center">
              Bạn đang giữ vững chuỗi tốt lắm!
            </p>
          </div>

          <!-- Milestone badges -->
          <div>
            <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wider mb-3">Huy hiệu</p>
            <div class="grid grid-cols-3 gap-2">
              <div
                v-for="days in ALL_MILESTONES"
                :key="days"
                class="rounded-[14px] p-3 flex flex-col items-center gap-1 transition-all"
                :class="streak?.achieved_milestones.includes(days)
                  ? 'bg-calor-light'
                  : 'bg-ios-gray6 opacity-40 grayscale'"
              >
                <span class="text-[24px]">{{ MILESTONE_META[days].emoji }}</span>
                <span class="text-[11px] font-semibold text-calor-deep text-center leading-tight">
                  {{ MILESTONE_META[days].name }}
                </span>
                <span class="text-[10px] text-ios-gray">{{ days }} ngày</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.sheet-enter-active, .sheet-leave-active {
  transition: transform 0.3s cubic-bezier(0.32, 0.72, 0, 1);
}
.sheet-enter-from, .sheet-leave-to {
  transform: translateY(100%);
}
</style>
