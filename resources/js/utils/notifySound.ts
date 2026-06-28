// Phát âm thanh thông báo riêng khi app đang mở (foreground). Lúc app đóng/khóa
// máy, thông báo đi qua hệ điều hành nên chỉ dùng được âm mặc định — không can thiệp được.
//
// iOS/Safari chặn play() nếu chưa từng có tương tác người dùng. Vì vậy ta "mồi"
// (prime) audio ở lần chạm đầu tiên: play muted rồi pause ngay, để các lần play()
// sau (khi nhận push) được phép kêu.

const SOUND_URL = '/sounds/notify.mp3'

let audio: HTMLAudioElement | null = null
let unlocked = false

function getAudio(): HTMLAudioElement {
  if (!audio) {
    audio = new Audio(SOUND_URL)
    audio.preload = 'auto'
  }
  return audio
}

/** Gọi một lần khi app khởi động — tự mồi audio ở tương tác đầu tiên của user. */
export function setupNotifySound(): void {
  if (typeof window === 'undefined') return

  const prime = () => {
    if (unlocked) return
    const a = getAudio()
    a.muted = true
    a.play()
      .then(() => {
        a.pause()
        a.currentTime = 0
        a.muted = false
        unlocked = true
        window.removeEventListener('pointerdown', prime)
      })
      .catch(() => { a.muted = false })
  }

  window.addEventListener('pointerdown', prime)
}

/** Phát âm thanh thông báo (no-op nếu trình duyệt chặn). */
export function playNotifySound(): void {
  const a = getAudio()
  a.currentTime = 0
  a.play().catch(() => {})
}
