<script setup lang="ts">
/**
 * Footer ghi nguồn tham chiếu dưới mỗi tư vấn dinh dưỡng — user click sẽ mở
 * danh sách citation đầy đủ (tiêu đề + tác giả + năm + link). Nếu không truyền
 * prop `citations` thì tự fetch từ /nutrition/standards.
 *
 * Đặt ở đây thay vì để trong từng trang để không phân tán logic tin cậy: mọi
 * chỗ hiển thị lời khuyên chỉ cần <AdvisorySource /> là có nguồn giống nhau.
 */
import { computed, onMounted, ref } from 'vue'
import { useNutritionStandards, type Citation } from '@/composables/useNutritionStandards'

const props = withDefaults(defineProps<{
  /** Citations riêng — nếu không truyền, dùng chuẩn chung. */
  citations?: Citation[] | null
  /** Compact = chỉ 1 dòng gọn, không hiển thị icon lớn. */
  compact?: boolean
}>(), {
  citations: null,
  compact:   false,
})

const { standards, load } = useNutritionStandards()
const expanded = ref(false)

onMounted(() => { if (!props.citations) load() })

const list = computed<Citation[]>(() =>
  props.citations && props.citations.length ? props.citations : (standards.value?.citations ?? [])
)

const shortLine = computed(() => {
  if (!list.value.length) return ''
  return list.value.map(c => `${c.author.split('—')[0].trim()} ${c.year}`).join(' · ')
})
</script>

<template>
  <div v-if="list.length" class="text-[11px] text-ios-gray leading-relaxed">
    <button
      type="button"
      class="flex items-center gap-1.5 ios-press w-full text-left"
      :class="compact ? 'py-1' : 'py-1.5'"
      @click="expanded = !expanded"
    >
      <svg viewBox="0 0 24 24" class="w-3.5 h-3.5 flex-shrink-0 opacity-70" fill="currentColor">
        <path d="M11 7h2v2h-2V7zm0 4h2v6h-2v-6zm1-9C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/>
      </svg>
      <!-- Khi expanded chỉ hiển thị label (không truncate short line trùng lặp với danh sách chi tiết ở dưới) -->
      <span v-if="expanded" class="flex-1 min-w-0 font-medium">Cơ sở tham chiếu:</span>
      <span v-else class="flex-1 min-w-0 truncate">
        <span class="font-medium">Cơ sở tham chiếu:</span> {{ shortLine }}
      </span>
      <svg viewBox="0 0 24 24" class="w-3 h-3 flex-shrink-0 opacity-60 transition-transform" :class="expanded ? 'rotate-180' : ''" fill="currentColor">
        <path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/>
      </svg>
    </button>

    <div v-if="expanded" class="mt-1 pl-5 flex flex-col gap-1.5">
      <a
        v-for="c in list" :key="c.id"
        :href="c.url" target="_blank" rel="noopener noreferrer"
        class="block text-ios-blue hover:underline"
      >
        <span class="font-medium">{{ c.title }}</span>
        <span class="text-ios-gray"> — {{ c.author }} ({{ c.year }})</span>
      </a>
    </div>
  </div>
</template>
