import type { ShareMealData, ShareOptions, ShareRatio, ShareVisibility } from '@/types/share'
import { SHARE_TEMPLATES } from '@/types/share'
import { renderShareImage } from '@/utils/shareImage'

/** Tuỳ chọn được ghi nhớ giữa các lần chia sẻ (mục "Lưu mẫu"). */
interface SharePrefs {
  templateId: string
  ratio: ShareRatio
  show: ShareVisibility
  sticker: string
  savedCaptions: string[]
  savedHashtags: string[]
}

const PREFS_KEY = 'share_meal_prefs'

const DEFAULT_PREFS: SharePrefs = {
  templateId: 'green',
  ratio: '4:5',
  show: { calories: true, macros: true, goal: true, logo: true, time: true, score: true, qr: false },
  sticker: '',
  savedCaptions: [],
  savedHashtags: ['#CaloEye', '#HealthyEating', '#MealTracker'],
}

function loadPrefs(): SharePrefs {
  try {
    const raw = localStorage.getItem(PREFS_KEY)
    if (!raw) return structuredClone(DEFAULT_PREFS)
    const parsed = JSON.parse(raw) as Partial<SharePrefs>
    return {
      ...structuredClone(DEFAULT_PREFS),
      ...parsed,
      show: { ...DEFAULT_PREFS.show, ...(parsed.show ?? {}) },
    }
  } catch {
    return structuredClone(DEFAULT_PREFS)
  }
}

// Singleton — mở sheet ở đâu cũng dùng chung tuỳ chọn đã ghi nhớ
const prefs = ref<SharePrefs>(loadPrefs())

watch(prefs, (p) => {
  try { localStorage.setItem(PREFS_KEY, JSON.stringify(p)) } catch { /* storage đầy/riêng tư — bỏ qua */ }
}, { deep: true })

/** Mạng xã hội hỗ trợ chia sẻ nhanh. `intent` nhận (text, url) → link mở tab mới; null → chia sẻ bằng ảnh (native sheet / tải về). */
export interface ShareNetwork {
  id: string
  name: string
  emoji: string
  intent: ((text: string, url: string) => string) | null
}

export const SHARE_NETWORKS: ShareNetwork[] = [
  { id: 'facebook',  name: 'Facebook',  emoji: '📘', intent: (t, u) => `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(u)}&quote=${encodeURIComponent(t)}` },
  { id: 'instagram', name: 'Instagram', emoji: '📸', intent: null },
  { id: 'threads',   name: 'Threads',   emoji: '🧵', intent: (t, u) => `https://www.threads.net/intent/post?text=${encodeURIComponent(`${t}\n${u}`)}` },
  { id: 'x',         name: 'X',         emoji: '✖️', intent: (t, u) => `https://twitter.com/intent/tweet?text=${encodeURIComponent(t)}&url=${encodeURIComponent(u)}` },
  { id: 'tiktok',    name: 'TikTok',    emoji: '🎵', intent: null },
  { id: 'messenger', name: 'Messenger', emoji: '💬', intent: (_t, u) => `fb-messenger://share?link=${encodeURIComponent(u)}` },
  { id: 'zalo',      name: 'Zalo',      emoji: '💙', intent: null },
  { id: 'telegram',  name: 'Telegram',  emoji: '✈️', intent: (t, u) => `https://t.me/share/url?url=${encodeURIComponent(u)}&text=${encodeURIComponent(t)}` },
  { id: 'whatsapp',  name: 'WhatsApp',  emoji: '🟢', intent: (t, u) => `https://wa.me/?text=${encodeURIComponent(`${t}\n${u}`)}` },
]

/**
 * Điểm dinh dưỡng heuristic 0–100 dựa trên độ cân bằng macro
 * (lý tưởng: protein 25% / carb 50% / fat 25% năng lượng). null nếu thiếu dữ liệu.
 */
export function nutritionScore(d: ShareMealData): number | null {
  const kcal = d.protein * 4 + d.carbs * 4 + d.fat * 9
  if (kcal <= 0) return null
  const p = (d.protein * 4) / kcal
  const c = (d.carbs * 4) / kcal
  const f = (d.fat * 9) / kcal
  const deviation = Math.abs(p - 0.25) + Math.abs(c - 0.5) + Math.abs(f - 0.25)
  return Math.max(40, Math.min(99, Math.round(100 - deviation * 90)))
}

/** Caption mặc định sinh từ dữ liệu bữa ăn + các khối đang bật. */
export function buildDefaultCaption(data: ShareMealData, show: ShareVisibility): string {
  const lines: string[] = [`🍱 ${data.food_name}`]
  if (show.calories) lines.push(`🔥 ${data.calories} kcal`)
  if (show.macros) {
    lines.push(`💪 Protein: ${data.protein}g`, `🥦 Carb: ${data.carbs}g`, `🥑 Fat: ${data.fat}g`)
  }
  if (show.goal && data.goal_percent != null) lines.push(`🎯 Đã đạt ${data.goal_percent}% mục tiêu hôm nay`)
  lines.push('', 'Đang cố gắng hoàn thành mục tiêu dinh dưỡng mỗi ngày 💚')
  return lines.join('\n')
}

export function useShareMeal() {
  const generating = ref(false)
  const toast = useToast()

  const appUrl = window.location.origin

  const template = computed(() =>
    SHARE_TEMPLATES.find(t => t.id === prefs.value.templateId) ?? SHARE_TEMPLATES[0],
  )

  function buildOptions(): ShareOptions {
    return {
      template: template.value,
      ratio: prefs.value.ratio,
      show: { ...prefs.value.show },
      sticker: prefs.value.sticker,
      qrUrl: appUrl,
    }
  }

  async function generateImage(data: ShareMealData): Promise<Blob | null> {
    generating.value = true
    try {
      return await renderShareImage(data, buildOptions())
    } catch {
      toast.error('Không tạo được ảnh chia sẻ')
      return null
    } finally {
      generating.value = false
    }
  }

  /** Chia sẻ ảnh qua native share sheet (PWA/mobile). Trả false nếu trình duyệt không hỗ trợ. */
  async function shareImageNative(data: ShareMealData, text: string): Promise<boolean> {
    const blob = await generateImage(data)
    if (!blob) return false
    const file = new File([blob], 'caloeye-meal.png', { type: 'image/png' })
    if (navigator.canShare?.({ files: [file] })) {
      try {
        await navigator.share({ files: [file], text, title: data.food_name })
        return true
      } catch {
        return true // người dùng bấm huỷ share sheet — không coi là lỗi
      }
    }
    return false
  }

  async function downloadImage(data: ShareMealData): Promise<boolean> {
    const blob = await generateImage(data)
    if (!blob) return false
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `caloeye-${Date.now()}.png`
    a.click()
    URL.revokeObjectURL(url)
    toast.success('Đã tải ảnh về máy')
    return true
  }

  /** Chia sẻ tới 1 mạng cụ thể: có web intent → mở tab; không có → native share ảnh, fallback tải ảnh về. */
  async function shareTo(network: ShareNetwork, data: ShareMealData, text: string): Promise<void> {
    if (network.intent) {
      window.open(network.intent(text, appUrl), '_blank', 'noopener')
      return
    }
    const shared = await shareImageNative(data, text)
    if (!shared) {
      await downloadImage(data)
      toast.info(`Ảnh đã tải về — mở ${network.name} để đăng nhé!`)
    }
  }

  async function copyText(text: string): Promise<void> {
    try {
      await navigator.clipboard.writeText(`${text}\n${appUrl}`)
      toast.success('Đã sao chép nội dung + liên kết')
    } catch {
      toast.error('Không sao chép được, hãy thử lại')
    }
  }

  function saveCaption(caption: string) {
    const c = caption.trim()
    if (!c) return
    if (!prefs.value.savedCaptions.includes(c)) {
      prefs.value.savedCaptions = [c, ...prefs.value.savedCaptions].slice(0, 5)
    }
    toast.success('Đã lưu mẫu caption')
  }

  function saveHashtag(tag: string) {
    const t = tag.trim().startsWith('#') ? tag.trim() : `#${tag.trim()}`
    if (t.length < 2) return
    if (!prefs.value.savedHashtags.includes(t)) {
      prefs.value.savedHashtags = [...prefs.value.savedHashtags, t].slice(0, 12)
    }
  }

  function removeSavedCaption(caption: string) {
    prefs.value.savedCaptions = prefs.value.savedCaptions.filter(c => c !== caption)
  }

  function removeSavedHashtag(tag: string) {
    prefs.value.savedHashtags = prefs.value.savedHashtags.filter(t => t !== tag)
  }

  return {
    prefs, template, generating,
    generateImage, shareImageNative, downloadImage, shareTo, copyText,
    saveCaption, saveHashtag, removeSavedCaption, removeSavedHashtag,
  }
}
