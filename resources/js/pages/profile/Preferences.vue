<script setup lang="ts">
import { ref, computed, nextTick, onMounted } from 'vue'
import { usePreferences } from '@/composables/usePreferences'
import type { PreferenceKind } from '@/types/preference'

const router = useRouter()
const { preferences, loading, error, fetchAll, add, remove, byKind } = usePreferences()

interface KindMeta {
  key: PreferenceKind
  title: string
  /** Mascot AVO đại diện cho nhóm */
  svg: string
  hint: string
  placeholder: string
  /** viền màu quanh mascot */
  ring: string
  /** nền + chữ cho pill và badge đếm */
  pill: string
}

// Mascot hero cho intro (dùng biến để Vite không cố import asset tĩnh từ /public)
const heroSvg = '/svg/AVO-16-yeu-thich.svg'

const KINDS: KindMeta[] = [
  {
    key: 'allergy', title: 'Dị ứng',
    svg: '/svg/AVO-19-di-ung-noi-man.svg',
    hint: 'Tuyệt đối không xuất hiện trong gợi ý',
    placeholder: 'Vd: tôm, đậu phộng, hải sản…',
    ring: 'ring-ios-red/35', pill: 'bg-ios-red/10 text-ios-red',
  },
  {
    key: 'diet', title: 'Chế độ ăn',
    svg: '/svg/AVO-18-che-do-an.svg',
    hint: 'Mọi gợi ý sẽ tuân theo chế độ này',
    placeholder: 'Vd: ăn chay, keto, low-carb…',
    ring: 'ring-ios-purple/35', pill: 'bg-ios-purple/10 text-ios-purple',
  },
  {
    key: 'dislike', title: 'Không thích',
    svg: '/svg/AVO-14-khong-thich-an.svg',
    hint: 'Sẽ tránh khi gợi ý món ăn',
    placeholder: 'Vd: mướp đắng, sầu riêng…',
    ring: 'ring-ios-orange/35', pill: 'bg-ios-orange/10 text-ios-orange',
  },
  {
    key: 'like', title: 'Thích',
    svg: '/svg/AVO-16-yeu-thich.svg',
    hint: 'Được ưu tiên gợi ý & món tương tự',
    placeholder: 'Vd: phở, bún bò, cá hồi…',
    ring: 'ring-ios-green/35', pill: 'bg-ios-green/10 text-ios-green',
  },
  {
    key: 'habit', title: 'Thói quen',
    svg: '/svg/AVO-17-thoi-quen.svg',
    hint: 'Nhịp ăn hằng ngày của bạn',
    placeholder: 'Vd: hay bỏ bữa sáng, ăn khuya…',
    ring: 'ring-ios-teal/35', pill: 'bg-ios-teal/10 text-ios-teal',
  },
]

// Form thêm mới
const addingKind = ref<PreferenceKind | null>(null)
const newLabel    = ref('')
const submitting  = ref(false)
const inputRef    = ref<HTMLInputElement | null>(null)

// Function ref: `ref` nằm trong v-for nên template ref chuỗi sẽ bị gom thành mảng.
// Chỉ đúng 1 input mount tại một thời điểm (v-if) nên gán thẳng phần tử là an toàn.
function setInputRef(el: Element | null) {
  inputRef.value = (el as HTMLInputElement) ?? null
}

const totalCount = computed(() => preferences.value.length)

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
        <svg viewBox="0 0 24 24" class="w-6 h-6" fill="none" style="stroke:var(--color-calor-green)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M15 18l-6-6 6-6"/>
        </svg>
      </button>
      <h1 class="text-[20px] font-bold text-black">Sở thích ăn uống</h1>
    </div>

    <!-- Intro card với mascot chính -->
    <div class="mx-5 mb-4 bg-gradient-to-br from-[#dfeacf] to-[#c6ddc0] rounded-[20px] px-4 py-3.5 flex items-center gap-3">
      <img :src="heroSvg" alt="AVO" class="w-14 h-14 flex-shrink-0 object-contain -my-1" draggable="false" />
      <p class="text-[13px] text-calor-deep leading-snug">
        Cho AVO biết khẩu vị của bạn nhé! Mình sẽ gợi ý món hợp gu và tránh những gì bạn không ăn được.
        <span class="text-calor-dark/70">Trợ lý AI cũng tự ghi nhớ khi bạn trò chuyện.</span>
      </p>
    </div>

    <div v-if="error" class="mx-5 mb-3 px-3 py-2 rounded-[12px] bg-ios-red/10 text-ios-red text-[13px]">
      {{ error }}
    </div>

    <!-- Loading skeleton -->
    <template v-if="loading && !preferences.length">
      <div v-for="i in 4" :key="i" class="mx-5 mb-3.5 h-24 rounded-[20px] bg-gray-200 animate-pulse"/>
    </template>

    <template v-else>
      <div v-for="meta in KINDS" :key="meta.key" class="px-5 mb-3.5">
        <div class="bg-white rounded-[20px] shadow-sm overflow-hidden">
          <!-- Header nhóm: mascot + tiêu đề + badge đếm -->
          <div class="flex items-center gap-3 px-3.5 pt-3.5 pb-3">
            <div
              class="w-[58px] h-[58px] rounded-full overflow-hidden ring-2 flex-shrink-0 bg-calor-light"
              :class="meta.ring"
            >
              <img :src="meta.svg" :alt="meta.title" class="w-full h-full object-cover" draggable="false" />
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <h2 class="text-[16px] font-bold text-black">{{ meta.title }}</h2>
                <span
                  v-if="byKind[meta.key].length"
                  class="min-w-[20px] h-5 px-1.5 rounded-full text-[11px] font-bold flex items-center justify-center"
                  :class="meta.pill"
                >{{ byKind[meta.key].length }}</span>
              </div>
              <p class="text-[12.5px] text-ios-gray leading-snug mt-0.5">{{ meta.hint }}</p>
            </div>
          </div>

          <div class="ios-separator mx-3.5"/>

          <!-- Body: pills + thêm -->
          <div class="px-3.5 pt-3 pb-3.5">
            <div v-if="!isEmpty(meta.key)" class="flex flex-wrap gap-2 mb-2.5">
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
            <p v-else class="text-[13px] text-ios-gray3 mb-2.5 px-0.5">Chưa có mục nào</p>

            <!-- Add form / button -->
            <div v-if="addingKind === meta.key" class="flex items-center gap-2">
              <input
                :ref="setInputRef"
                v-model="newLabel"
                type="text"
                maxlength="100"
                :placeholder="meta.placeholder"
                class="flex-1 min-w-0 bg-ios-gray6 rounded-[10px] px-3 py-2 text-[14px] outline-none focus:ring-2 focus:ring-calor-green/40"
                @keydown.enter.prevent="submitAdd"
                @keydown.esc="cancelAdd"
              >
              <button
                class="px-3.5 py-2 rounded-[10px] bg-calor-green text-white text-[13px] font-semibold ios-press disabled:opacity-40 flex-shrink-0"
                :disabled="!newLabel.trim() || submitting"
                @click="submitAdd"
              >Thêm</button>
              <button class="px-1 py-2 text-ios-gray text-[13px] ios-press flex-shrink-0" @click="cancelAdd">Huỷ</button>
            </div>
            <button
              v-else
              class="inline-flex items-center gap-1.5 text-[13px] font-semibold text-calor-green ios-press px-1 py-0.5"
              @click="openAdd(meta.key)"
            >
              <svg viewBox="0 0 24 24" class="w-4 h-4" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
              Thêm mục
            </button>
          </div>
        </div>
      </div>

      <!-- Tổng kết nhỏ -->
      <p v-if="totalCount" class="text-center text-[12px] text-ios-gray3 mt-1">
        Đã ghi nhớ {{ totalCount }} mục · AVO dùng để cá nhân hoá gợi ý
      </p>
    </template>
  </div>
</template>
