<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { usePreferences } from '@/composables/usePreferences'
import type { PreferenceKind } from '@/types/preference'

const router = useRouter()
const { loading, error, fetchAll, add, remove, byKind } = usePreferences()

interface KindMeta {
  key: PreferenceKind
  title: string
  emoji: string
  /** class nền cho pill */
  pill: string
  hint: string
}

const KINDS: KindMeta[] = [
  { key: 'allergy', title: 'Dị ứng',      emoji: '🚫', pill: 'bg-ios-red/10 text-ios-red',       hint: 'Tuyệt đối không xuất hiện trong gợi ý' },
  { key: 'diet',    title: 'Chế độ ăn',   emoji: '🥗', pill: 'bg-ios-purple/10 text-ios-purple', hint: 'Vd: ăn chay, keto, low-carb' },
  { key: 'dislike', title: 'Không thích', emoji: '👎', pill: 'bg-ios-orange/10 text-ios-orange', hint: 'Sẽ tránh khi gợi ý món' },
  { key: 'like',    title: 'Thích',       emoji: '👍', pill: 'bg-ios-green/10 text-ios-green',   hint: 'Được ưu tiên gợi ý' },
  { key: 'habit',   title: 'Thói quen',   emoji: '🔁', pill: 'bg-ios-blue/10 text-ios-blue',     hint: 'Vd: không ăn sáng, hay ăn khuya' },
]

// Form thêm mới
const addingKind = ref<PreferenceKind | null>(null)
const newLabel    = ref('')
const submitting  = ref(false)
const inputRef    = ref<HTMLInputElement | null>(null)

function openAdd(kind: PreferenceKind) {
  addingKind.value = kind
  newLabel.value   = ''
  nextTick(() => inputRef.value?.focus())
}

function cancelAdd() {
  addingKind.value = null
  newLabel.value   = ''
}

async function submitAdd() {
  if (!addingKind.value || !newLabel.value.trim() || submitting.value) return
  submitting.value = true
  const ok = await add(addingKind.value, newLabel.value)
  submitting.value = false
  if (ok) cancelAdd()
}

const isEmpty = (kind: PreferenceKind) => byKind.value[kind].length === 0

onMounted(() => fetchAll(true))
</script>

<template>
  <div class="pb-10">
    <!-- Header -->
    <div class="px-4 pt-2 pb-3 flex items-center gap-2">
      <button class="w-9 h-9 -ml-1 flex items-center justify-center ios-press" aria-label="Quay lại" @click="router.back()">
        <svg viewBox="0 0 24 24" class="w-6 h-6" fill="none" stroke="#007AFF" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M15 18l-6-6 6-6"/>
        </svg>
      </button>
      <h1 class="text-[20px] font-bold text-black">Sở thích ăn uống</h1>
    </div>

    <p class="px-5 text-[13px] text-ios-gray leading-snug mb-3">
      CaloEye ghi nhớ những điều này để tư vấn hợp khẩu vị và tránh món bạn không ăn được.
      Trợ lý AI cũng tự thêm khi bạn chia sẻ trong lúc trò chuyện.
    </p>

    <div v-if="error" class="mx-5 mb-3 px-3 py-2 rounded-[12px] bg-ios-red/10 text-ios-red text-[13px]">
      {{ error }}
    </div>

    <template v-if="loading && !preferences.length">
      <div v-for="i in 3" :key="i" class="mx-5 mb-3 h-20 rounded-[16px] bg-gray-200 animate-pulse"/>
    </template>

    <template v-else>
      <div v-for="meta in KINDS" :key="meta.key" class="px-5 mb-4">
        <div class="flex items-center gap-1.5 mb-2 px-1">
          <span>{{ meta.emoji }}</span>
          <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide">{{ meta.title }}</p>
        </div>

        <div class="bg-white rounded-[16px] shadow-sm p-3">
          <!-- Pills -->
          <div v-if="!isEmpty(meta.key)" class="flex flex-wrap gap-2 mb-2">
            <span
              v-for="p in byKind[meta.key]"
              :key="p.id"
              class="inline-flex items-center gap-1.5 pl-3 pr-1.5 py-1.5 rounded-full text-[13px] font-medium"
              :class="meta.pill"
            >
              {{ p.label }}
              <span v-if="p.source === 'chat'" title="Ghi nhớ từ trò chuyện" class="opacity-70 text-[11px]">💬</span>
              <button
                class="w-5 h-5 rounded-full bg-black/5 flex items-center justify-center ios-press"
                aria-label="Xoá"
                @click="remove(p.id)"
              >
                <svg viewBox="0 0 24 24" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <path d="M6 6l12 12M18 6L6 18"/>
                </svg>
              </button>
            </span>
          </div>
          <p v-else class="text-[13px] text-ios-gray3 mb-2 px-1">{{ meta.hint }}</p>

          <!-- Add form / button -->
          <div v-if="addingKind === meta.key" class="flex items-center gap-2">
            <input
              ref="inputRef"
              v-model="newLabel"
              type="text"
              maxlength="100"
              placeholder="Nhập rồi nhấn Thêm..."
              class="flex-1 bg-ios-gray6 rounded-[10px] px-3 py-2 text-[14px] outline-none"
              @keydown.enter.prevent="submitAdd"
              @keydown.esc="cancelAdd"
            >
            <button
              class="px-3 py-2 rounded-[10px] bg-ios-blue text-white text-[13px] font-semibold ios-press disabled:opacity-40"
              :disabled="!newLabel.trim() || submitting"
              @click="submitAdd"
            >Thêm</button>
            <button class="px-2 py-2 text-ios-gray text-[13px] ios-press" @click="cancelAdd">Huỷ</button>
          </div>
          <button
            v-else
            class="flex items-center gap-1.5 text-[13px] font-semibold text-ios-blue ios-press px-1"
            @click="openAdd(meta.key)"
          >
            <svg viewBox="0 0 24 24" class="w-4 h-4" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            Thêm
          </button>
        </div>
      </div>
    </template>
  </div>
</template>
