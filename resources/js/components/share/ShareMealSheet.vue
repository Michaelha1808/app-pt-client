<script setup lang="ts">
import { renderSVG } from 'uqr'
import type { ShareMealData, ShareRatio, ShareVisibility } from '@/types/share'
import { SHARE_TEMPLATES } from '@/types/share'
import { SHARE_NETWORKS, buildDefaultCaption, nutritionScore, useShareMeal } from '@/composables/useShareMeal'

const props = defineProps<{
  open: boolean
  meal: ShareMealData | null
}>()

const emit = defineEmits<{
  'update:open': [boolean]
}>()

const {
  prefs, template, generating,
  shareImageNative, downloadImage, shareTo, copyText,
  saveCaption, saveHashtag, removeSavedCaption,
} = useShareMeal()

const caption      = ref('')
const selectedTags = ref<string[]>([])
const newTag       = ref('')

const canNativeShare = typeof navigator !== 'undefined' && !!navigator.share

// Dữ liệu đưa vào ảnh: meal + điểm dinh dưỡng tính sẵn
const shareData = computed<ShareMealData | null>(() =>
  props.meal ? { ...props.meal, score: nutritionScore(props.meal) } : null,
)

const shareText = computed(() => {
  const tags = selectedTags.value.join(' ')
  return tags ? `${caption.value.trimEnd()}\n\n${tags}` : caption.value.trimEnd()
})

// Mỗi lần mở sheet: sinh caption mặc định + chọn sẵn hashtag đã lưu
watch(() => props.open, (o) => {
  if (o && props.meal) {
    caption.value = buildDefaultCaption(props.meal, prefs.value.show)
    selectedTags.value = prefs.value.savedHashtags.slice(0, 3)
  }
})

function regenCaption() {
  if (props.meal) caption.value = buildDefaultCaption(props.meal, prefs.value.show)
}

function close() {
  emit('update:open', false)
}

// ── Tuỳ chọn hiển thị ──
const visToggles: { key: keyof ShareVisibility; label: string }[] = [
  { key: 'calories', label: '🔥 Calo' },
  { key: 'macros',   label: '💪 Macro' },
  { key: 'goal',     label: '🎯 Mục tiêu' },
  { key: 'score',    label: '🥗 Điểm DD' },
  { key: 'logo',     label: '🏷 Logo' },
  { key: 'time',     label: '🕐 Thời gian' },
  { key: 'qr',       label: '🔳 QR Code' },
]

// QR preview (SVG data URI, sinh 1 lần — URL app không đổi trong phiên)
const qrDataUri = `data:image/svg+xml;utf8,${encodeURIComponent(renderSVG(window.location.origin, { border: 1 }))}`

// Logo nằm trong public/ (asset runtime) — bind động để Vite không compile thành module import
const logoUrl = '/logo/caloreye_icon_192.png'

const ratios: ShareRatio[] = ['1:1', '4:5', '9:16', '16:9']
const stickers = ['', '💪', '🔥', '🥗', '😋', '🏆', '❤️']
const quickEmojis = ['😋', '💪', '🔥', '🥗', '💚', '🏆', '✨', '🍽️']

function toggleShow(key: keyof ShareVisibility) {
  prefs.value.show[key] = !prefs.value.show[key]
}

function toggleTag(tag: string) {
  selectedTags.value = selectedTags.value.includes(tag)
    ? selectedTags.value.filter(t => t !== tag)
    : [...selectedTags.value, tag]
}

function addTag() {
  const t = newTag.value.trim()
  if (!t) return
  const tag = t.startsWith('#') ? t : `#${t}`
  saveHashtag(tag)
  if (!selectedTags.value.includes(tag)) selectedTags.value.push(tag)
  newTag.value = ''
}

function appendEmoji(e: string) {
  caption.value = `${caption.value} ${e}`.trimStart()
}

// ── Kích thước preview theo tỷ lệ (thu nhỏ, giữ đúng aspect) ──
const previewStyle = computed(() => {
  const [rw, rh] = prefs.value.ratio.split(':').map(Number)
  return {
    aspectRatio: `${rw} / ${rh}`,
    // Giới hạn chiều cao 400px nhưng vẫn giữ đúng tỷ lệ → chiều rộng tự co lại
    width: `min(100%, calc(400px * ${rw} / ${rh}))`,
    background: `linear-gradient(160deg, ${template.value.bg[0]}, ${template.value.bg[1]})`,
  }
})

// 16:9 dùng layout ngang (ảnh trái, card phải) — khớp với ảnh canvas xuất ra
const isLandscape = computed(() => prefs.value.ratio === '16:9')

// % chiều cao vùng ảnh theo tỷ lệ — khớp hệ số trong utils/shareImage.ts
const imgHeightPct = computed(() =>
  prefs.value.ratio === '9:16' ? 54 : prefs.value.ratio === '4:5' ? 44 : 40,
)

// Khung thấp (1:1, 16:9) → giảm cỡ chữ/khoảng cách để card vừa khung
const compact = computed(() => isLandscape.value || prefs.value.ratio === '1:1')

// ── Hành động chia sẻ ──
async function onNativeShare() {
  if (!shareData.value || generating.value) return
  const ok = await shareImageNative(shareData.value, shareText.value)
  if (!ok) await downloadImage(shareData.value)
}

async function onNetwork(id: string) {
  const network = SHARE_NETWORKS.find(n => n.id === id)
  if (!network || !shareData.value || generating.value) return
  await shareTo(network, shareData.value, shareText.value)
}

async function onDownload() {
  if (!shareData.value || generating.value) return
  await downloadImage(shareData.value)
}
</script>

<template>
  <Teleport to="body">
    <Transition name="share-fade">
      <div v-if="open && meal" class="fixed inset-0 z-[70] flex items-end justify-center">
        <div class="absolute inset-0 bg-black/45 backdrop-blur-[2px]" @click="close" />

        <div class="relative w-full max-w-[430px] max-h-[92dvh] bg-[#F2F8F5] rounded-t-[28px] shadow-2xl animate-slideUpSheet flex flex-col overflow-hidden">
          <!-- Grabber + header -->
          <div class="flex-shrink-0 pt-2.5 pb-2 px-5">
            <div class="w-10 h-1 rounded-full bg-ios-gray4 mx-auto mb-2.5" />
            <div class="flex items-center justify-between">
              <h2 class="text-[18px] font-bold text-black">📤 Chia sẻ bữa ăn</h2>
              <button class="w-8 h-8 rounded-full bg-ios-gray5 flex items-center justify-center ios-press" @click="close">
                <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#8a9a7d">
                  <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
              </button>
            </div>
          </div>

          <div class="flex-1 overflow-y-auto px-5 pb-5">

            <!-- ══ Preview social card (live) ══ -->
            <div
              class="relative mx-auto rounded-[20px] shadow-lg overflow-hidden transition-all duration-300"
              :style="previewStyle"
            >
              <div class="absolute inset-0 flex p-3" :class="isLandscape ? 'flex-row gap-2' : 'flex-col'">
                <!-- Ảnh món ăn -->
                <div
                  class="relative rounded-[14px] overflow-hidden flex-shrink-0"
                  :style="isLandscape ? 'width: 46%; height: 100%' : `width: 100%; height: ${imgHeightPct}%`"
                >
                  <img v-if="meal.image" :src="meal.image" class="w-full h-full object-cover" alt="" />
                  <div
                    v-else
                    class="w-full h-full flex items-center justify-center text-5xl"
                    :class="template.dark ? 'bg-white/10' : 'bg-calor-green/10'"
                  >🍽️</div>
                  <div class="absolute inset-x-0 bottom-0 h-1/3 bg-gradient-to-t from-black/35 to-transparent" />

                  <!-- Badge điểm dinh dưỡng -->
                  <div
                    v-if="prefs.show.score && shareData?.score != null"
                    class="absolute top-2 left-2 bg-black/45 text-white text-[10px] font-bold rounded-full px-2 py-1"
                  >🥗 Dinh dưỡng {{ shareData.score }}/100</div>

                  <!-- Sticker -->
                  <div v-if="prefs.sticker" class="absolute top-1 right-2 text-3xl rotate-12 drop-shadow">{{ prefs.sticker }}</div>
                </div>

                <!-- Card thông tin (overflow-hidden để nội dung không tràn ra ngoài khung ở tỷ lệ thấp) -->
                <div
                  class="flex-1 rounded-[14px] px-3.5 py-2.5 flex flex-col min-w-0 min-h-0 overflow-hidden"
                  :class="isLandscape ? '' : 'mt-2'"
                  :style="`background: ${template.card}; ${template.dark ? '' : 'box-shadow: 0 4px 14px rgba(0,0,0,0.07)'}`"
                >
                  <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                      <p
                        class="font-bold leading-tight"
                        :class="compact ? 'text-[12px] line-clamp-1' : 'text-[14px] line-clamp-2'"
                        :style="`color: ${template.text}`"
                      >{{ meal.food_name }}</p>
                      <p class="text-[10px] mt-0.5 truncate" :style="`color: ${template.sub}`">
                        {{ [meal.serving, prefs.show.time ? meal.logged_at : null].filter(Boolean).join(' · ') }}
                      </p>
                    </div>
                    <div v-if="prefs.show.calories" class="text-right flex-shrink-0">
                      <p class="font-extrabold leading-none" :class="compact ? 'text-[17px]' : 'text-[22px]'" :style="`color: ${template.accent}`">{{ meal.calories }}</p>
                      <p class="text-[9px] font-semibold" :style="`color: ${template.sub}`">kcal</p>
                    </div>
                  </div>

                  <!-- Macro chips -->
                  <div v-if="prefs.show.macros" class="grid grid-cols-3 gap-1.5" :class="compact ? 'mt-1' : 'mt-2'">
                    <div
                      v-for="m in [{ l: 'Protein', v: `${meal.protein}g` }, { l: 'Carb', v: `${meal.carbs}g` }, { l: 'Chất béo', v: `${meal.fat}g` }]"
                      :key="m.l"
                      class="rounded-[8px] text-center"
                      :class="compact ? 'py-1' : 'py-1.5'"
                      :style="`background: ${template.dark ? 'rgba(255,255,255,0.10)' : 'rgba(24,168,116,0.08)'}`"
                    >
                      <p class="text-[11px] font-bold leading-tight" :style="`color: ${template.text}`">{{ m.v }}</p>
                      <p class="text-[8px]" :style="`color: ${template.sub}`">{{ m.l }}</p>
                    </div>
                  </div>

                  <!-- Mục tiêu -->
                  <div v-if="prefs.show.goal && meal.goal_percent != null" :class="compact ? 'mt-1' : 'mt-2'">
                    <div class="flex justify-between text-[9px] font-semibold mb-1">
                      <span :style="`color: ${template.sub}`">Mục tiêu hôm nay</span>
                      <span :style="`color: ${template.accent}`">{{ meal.goal_percent }}%</span>
                    </div>
                    <div class="h-1.5 rounded-full overflow-hidden" :style="`background: ${template.dark ? 'rgba(255,255,255,0.15)' : 'rgba(0,0,0,0.08)'}`">
                      <div class="h-full rounded-full transition-all duration-300" :style="`width: ${Math.min(meal.goal_percent, 100)}%; background: ${template.accent}`" />
                    </div>
                  </div>

                  <!-- Footer logo + ngày + QR -->
                  <div v-if="prefs.show.logo || prefs.show.time || prefs.show.qr" class="mt-auto pt-1.5 flex items-center justify-between">
                    <div v-if="prefs.show.logo" class="flex items-center gap-1.5">
                      <img :src="logoUrl" class="w-4 h-4 rounded-[4px]" alt="" />
                      <span class="text-[10px] font-bold" :style="`color: ${template.text}`">CaloEye</span>
                    </div>
                    <div class="flex items-center gap-1.5 ml-auto">
                      <span v-if="prefs.show.time" class="text-[9px]" :style="`color: ${template.sub}`">{{ new Date().toLocaleDateString('vi-VN') }}</span>
                      <img v-if="prefs.show.qr" :src="qrDataUri" class="w-7 h-7 rounded-[4px] bg-white" alt="QR" />
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- ══ Template picker ══ -->
            <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mt-4 mb-2">Chọn nền</p>
            <div class="flex gap-2.5 overflow-x-auto pb-1 -mx-5 px-5">
              <button
                v-for="tpl in SHARE_TEMPLATES"
                :key="tpl.id"
                class="flex flex-col items-center gap-1 flex-shrink-0 ios-press"
                @click="prefs.templateId = tpl.id"
              >
                <div
                  class="w-12 h-12 rounded-[14px] border-2 transition-all"
                  :class="prefs.templateId === tpl.id ? 'border-calor-green scale-105' : 'border-transparent'"
                  :style="`background: linear-gradient(160deg, ${tpl.bg[0]}, ${tpl.bg[1]}); box-shadow: 0 2px 8px rgba(0,0,0,0.10)`"
                />
                <span class="text-[10px] font-medium" :class="prefs.templateId === tpl.id ? 'text-calor-green' : 'text-ios-gray'">{{ tpl.name }}</span>
              </button>
            </div>

            <!-- ══ Tỷ lệ ảnh ══ -->
            <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mt-3 mb-2">Tỷ lệ ảnh</p>
            <div class="bg-ios-gray5 rounded-[10px] p-1 flex">
              <button
                v-for="r in ratios"
                :key="r"
                class="flex-1 py-1.5 rounded-[8px] text-[13px] font-semibold transition-all"
                :class="prefs.ratio === r ? 'bg-white text-black shadow-sm' : 'text-ios-gray'"
                @click="prefs.ratio = r"
              >{{ r }}<span v-if="r === '9:16'" class="text-[9px] font-normal"> Story</span></button>
            </div>

            <!-- ══ Hiển thị trên ảnh ══ -->
            <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mt-4 mb-2">Hiển thị</p>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="vt in visToggles"
                :key="vt.key"
                class="px-3 py-1.5 rounded-full text-[12px] font-semibold border transition-all ios-press"
                :class="prefs.show[vt.key]
                  ? 'bg-calor-light border-calor-mint text-calor-deep'
                  : 'bg-white border-ios-gray4 text-ios-gray'"
                @click="toggleShow(vt.key)"
              >{{ vt.label }}</button>
            </div>

            <!-- ══ Sticker ══ -->
            <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mt-4 mb-2">Sticker</p>
            <div class="flex gap-2">
              <button
                v-for="s in stickers"
                :key="s || 'none'"
                class="w-10 h-10 rounded-[12px] flex items-center justify-center text-xl border transition-all ios-press"
                :class="prefs.sticker === s ? 'bg-calor-light border-calor-green' : 'bg-white border-ios-gray5'"
                @click="prefs.sticker = s"
              >
                <span v-if="s">{{ s }}</span>
                <svg v-else viewBox="0 0 24 24" class="w-4 h-4" fill="#b8c0ac"><path d="M12 2a10 10 0 100 20 10 10 0 000-20zm0 18a8 8 0 118-8 8 8 0 01-8 8zM7 11h10v2H7z"/></svg>
              </button>
            </div>

            <!-- ══ Caption ══ -->
            <div class="flex items-center justify-between mt-4 mb-2">
              <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide">Caption</p>
              <div class="flex gap-3">
                <button class="text-[12px] font-semibold text-ios-blue ios-press" @click="regenCaption">↻ Tạo lại</button>
                <button class="text-[12px] font-semibold text-calor-green ios-press" @click="saveCaption(caption)">💾 Lưu mẫu</button>
              </div>
            </div>
            <textarea
              v-model="caption"
              rows="4"
              class="w-full bg-white rounded-[14px] px-3.5 py-3 text-[14px] text-black leading-relaxed outline-none border border-transparent focus:border-calor-mint resize-none"
              placeholder="Viết vài dòng về bữa ăn của bạn..."
            />
            <!-- Emoji nhanh -->
            <div class="flex gap-1.5 mt-1.5 overflow-x-auto pb-1">
              <button
                v-for="e in quickEmojis"
                :key="e"
                class="w-8 h-8 rounded-[10px] bg-white flex-shrink-0 flex items-center justify-center text-base ios-press shadow-sm"
                @click="appendEmoji(e)"
              >{{ e }}</button>
            </div>

            <!-- Caption đã lưu -->
            <div v-if="prefs.savedCaptions.length" class="mt-2 space-y-1.5">
              <div
                v-for="c in prefs.savedCaptions"
                :key="c"
                class="flex items-center gap-2 bg-white rounded-[12px] px-3 py-2"
              >
                <button class="flex-1 text-left text-[12px] text-ios-gray truncate ios-press" @click="caption = c">{{ c.split('\n')[0] }}…</button>
                <button class="text-ios-gray3 ios-press flex-shrink-0" @click="removeSavedCaption(c)">
                  <svg viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                </button>
              </div>
            </div>

            <!-- ══ Hashtag ══ -->
            <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mt-4 mb-2">Hashtag</p>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="tag in prefs.savedHashtags"
                :key="tag"
                class="px-3 py-1.5 rounded-full text-[12px] font-semibold border transition-all ios-press"
                :class="selectedTags.includes(tag)
                  ? 'bg-ios-blue text-white border-ios-blue'
                  : 'bg-white border-ios-gray4 text-ios-gray'"
                @click="toggleTag(tag)"
              >{{ tag }}</button>
            </div>
            <div class="flex gap-2 mt-2">
              <input
                v-model="newTag"
                class="flex-1 bg-white rounded-[12px] px-3.5 py-2 text-[13px] outline-none border border-transparent focus:border-calor-mint"
                placeholder="Thêm hashtag mới..."
                @keydown.enter.prevent="addTag"
              />
              <button class="px-4 rounded-[12px] bg-calor-green text-white text-[13px] font-semibold ios-press disabled:opacity-40" :disabled="!newTag.trim()" @click="addTag">Thêm</button>
            </div>

            <!-- ══ Chia sẻ nhanh ══ -->
            <p class="text-[13px] font-semibold text-ios-gray uppercase tracking-wide mt-5 mb-2">Chia sẻ đến</p>
            <div class="grid grid-cols-4 gap-2.5">
              <button
                v-for="n in SHARE_NETWORKS"
                :key="n.id"
                class="flex flex-col items-center gap-1 bg-white rounded-[14px] py-2.5 ios-press shadow-sm"
                :disabled="generating"
                @click="onNetwork(n.id)"
              >
                <span class="text-xl">{{ n.emoji }}</span>
                <span class="text-[10px] font-medium text-ios-gray">{{ n.name }}</span>
              </button>
              <button class="flex flex-col items-center gap-1 bg-white rounded-[14px] py-2.5 ios-press shadow-sm" @click="copyText(shareText)">
                <span class="text-xl">🔗</span>
                <span class="text-[10px] font-medium text-ios-gray">Sao chép</span>
              </button>
              <button class="flex flex-col items-center gap-1 bg-white rounded-[14px] py-2.5 ios-press shadow-sm" :disabled="generating" @click="onDownload">
                <span class="text-xl">⬇️</span>
                <span class="text-[10px] font-medium text-ios-gray">Tải ảnh</span>
              </button>
            </div>

            <!-- Nút share chính (native sheet) -->
            <button
              class="w-full mt-4 h-[52px] rounded-[14px] bg-calor-green text-white text-[16px] font-semibold ios-press flex items-center justify-center gap-2 disabled:opacity-50"
              :disabled="generating"
              @click="onNativeShare"
            >
              <div v-if="generating" class="w-5 h-5 rounded-full border-2 border-white border-t-transparent animate-spin" />
              <template v-else>
                <svg viewBox="0 0 24 24" class="w-5 h-5" fill="white">
                  <path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81a3 3 0 10-3-3c0 .24.04.47.09.7L8.04 9.81A2.99 2.99 0 003 12a3 3 0 005.04 2.19l7.12 4.16c-.05.21-.08.43-.08.65a2.92 2.92 0 102.92-2.92z"/>
                </svg>
                {{ canNativeShare ? 'Chia sẻ ngay' : 'Tạo & tải ảnh chia sẻ' }}
              </template>
            </button>

            <p class="text-[11px] text-ios-gray text-center mt-2 leading-relaxed">
              Với Instagram, TikTok và Zalo, ảnh sẽ được chia sẻ qua menu hệ thống hoặc tải về máy để bạn đăng thủ công.
            </p>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.share-fade-enter-active,
.share-fade-leave-active {
  transition: opacity 0.22s ease;
}
.share-fade-enter-from,
.share-fade-leave-to {
  opacity: 0;
}
</style>
