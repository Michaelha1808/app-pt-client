/** Dữ liệu bữa ăn đưa vào module chia sẻ — gom từ Result (vừa lưu) hoặc History (bữa đã log). */
export interface ShareMealData {
  food_name: string
  serving?: string | null
  calories: number
  protein: number
  carbs: number
  fat: number
  /** dataURL (ảnh vừa chụp) hoặc URL (ảnh đã lưu). null → dùng nền emoji. */
  image?: string | null
  /** Chuỗi hiển thị thời gian ghi nhận, ví dụ "Trưa · 12:30, 12/07/2026" */
  logged_at?: string | null
  /** % mục tiêu calo hôm nay đã đạt (sau bữa này). null → ẩn dòng mục tiêu. */
  goal_percent?: number | null
  /** Điểm dinh dưỡng 0–100 (tính bằng nutritionScore). null → ẩn badge. */
  score?: number | null
}

export type ShareRatio = '1:1' | '4:5' | '9:16' | '16:9'

/** Các khối thông tin có thể bật/tắt trên ảnh + caption. */
export interface ShareVisibility {
  calories: boolean
  macros: boolean
  goal: boolean
  logo: boolean
  time: boolean
  score: boolean
  /** QR code dẫn về app, góc phải dưới ảnh. */
  qr: boolean
}

export interface ShareTemplate {
  id: string
  name: string
  /** 2 stop gradient nền (dùng chung cho DOM preview và canvas). */
  bg: [string, string]
  /** Màu nền card thông tin (rgba để nổi trên gradient). */
  card: string
  text: string
  sub: string
  accent: string
  /** true → chữ trắng, overlay đậm hơn. */
  dark: boolean
}

export const SHARE_TEMPLATES: ShareTemplate[] = [
  { id: 'minimal',  name: 'Minimal White', bg: ['#FFFFFF', '#F1F3F2'], card: 'rgba(255,255,255,0.92)', text: '#111111', sub: '#8E8E93', accent: '#18A874', dark: false },
  { id: 'green',    name: 'Healthy Green', bg: ['#0C4D3D', '#18A874'], card: 'rgba(255,255,255,0.14)', text: '#FFFFFF', sub: 'rgba(255,255,255,0.72)', accent: '#9FE1CB', dark: true },
  { id: 'dark',     name: 'Dark Mode',     bg: ['#101613', '#1C2B24'], card: 'rgba(255,255,255,0.08)', text: '#FFFFFF', sub: 'rgba(255,255,255,0.6)',  accent: '#4ADE9C', dark: true },
  { id: 'gradient', name: 'Gradient',      bg: ['#7C3AED', '#EC4899'], card: 'rgba(255,255,255,0.16)', text: '#FFFFFF', sub: 'rgba(255,255,255,0.75)', accent: '#FDE68A', dark: true },
  { id: 'fitness',  name: 'Fitness',       bg: ['#111827', '#1F2937'], card: 'rgba(255,255,255,0.08)', text: '#FFFFFF', sub: 'rgba(255,255,255,0.6)',  accent: '#F97316', dark: true },
  { id: 'modern',   name: 'Modern Card',   bg: ['#E1F5EE', '#F2F8F5'], card: 'rgba(255,255,255,0.95)', text: '#0C4D3D', sub: '#5B7A6F', accent: '#18A874', dark: false },
]

export interface ShareOptions {
  template: ShareTemplate
  ratio: ShareRatio
  show: ShareVisibility
  /** Emoji sticker vẽ đè góc ảnh, '' → không có. */
  sticker: string
  /** URL encode vào QR code (khi show.qr bật). */
  qrUrl?: string
}

/** Kích thước xuất ảnh theo tỷ lệ (px). */
export const RATIO_SIZES: Record<ShareRatio, { w: number; h: number }> = {
  '1:1':  { w: 1080, h: 1080 },
  '4:5':  { w: 1080, h: 1350 },
  '9:16': { w: 1080, h: 1920 },
  '16:9': { w: 1920, h: 1080 },
}
