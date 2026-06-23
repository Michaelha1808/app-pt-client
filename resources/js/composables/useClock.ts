import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import type { Ref } from 'vue'

export interface ClockState {
  date: Readonly<Ref<string>>
  time: Readonly<Ref<string>>
  ms: Readonly<Ref<string>>
  mounted: Readonly<Ref<boolean>>
}

export function useClock(): ClockState {
  const now = ref<Date | null>(null)
  let timerId: ReturnType<typeof setInterval> | undefined

  const date = computed(() => {
    if (!now.value) {
      return ''
    }

    return now.value.toLocaleDateString('vi-VN', {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    })
  })

  const time = computed(() => {
    if (!now.value) {
      return ''
    }

    return now.value.toLocaleTimeString('vi-VN', {
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: false,
    })
  })

  const ms = computed(() => String(now.value?.getMilliseconds() ?? 0).padStart(3, '0'))
  const mounted = computed(() => now.value !== null)

  onMounted(() => {
    const tick = () => {
      now.value = new Date()
    }

    tick()
    timerId = setInterval(tick, 100)
  })

  onBeforeUnmount(() => {
    if (timerId) {
      clearInterval(timerId)
    }
  })

  return { date, time, ms, mounted }
}
