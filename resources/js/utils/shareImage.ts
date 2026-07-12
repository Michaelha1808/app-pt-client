import { encode as encodeQR } from 'uqr'
import type { ShareMealData, ShareOptions } from '@/types/share'
import { RATIO_SIZES } from '@/types/share'

const FONT = `-apple-system, 'SF Pro Display', 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif`

function roundRect(ctx: CanvasRenderingContext2D, x: number, y: number, w: number, h: number, r: number) {
  ctx.beginPath()
  if (typeof ctx.roundRect === 'function') {
    ctx.roundRect(x, y, w, h, r)
    return
  }
  // Fallback cho WebView cũ chưa có CanvasRenderingContext2D.roundRect
  ctx.moveTo(x + r, y)
  ctx.arcTo(x + w, y, x + w, y + h, r)
  ctx.arcTo(x + w, y + h, x, y + h, r)
  ctx.arcTo(x, y + h, x, y, r)
  ctx.arcTo(x, y, x + w, y, r)
  ctx.closePath()
}

function loadImage(src: string): Promise<HTMLImageElement | null> {
  return new Promise((resolve) => {
    const img = new Image()
    if (!src.startsWith('data:')) img.crossOrigin = 'anonymous'
    img.onload  = () => resolve(img)
    img.onerror = () => resolve(null)
    img.src = src
  })
}

/** Vẽ ảnh theo kiểu object-fit: cover trong vùng bo góc. */
function drawCover(ctx: CanvasRenderingContext2D, img: HTMLImageElement, x: number, y: number, w: number, h: number, r: number) {
  ctx.save()
  roundRect(ctx, x, y, w, h, r)
  ctx.clip()
  const scale = Math.max(w / img.width, h / img.height)
  const dw = img.width * scale
  const dh = img.height * scale
  ctx.drawImage(img, x + (w - dw) / 2, y + (h - dh) / 2, dw, dh)
  ctx.restore()
}

function wrapLines(ctx: CanvasRenderingContext2D, text: string, maxWidth: number, maxLines: number): string[] {
  const words = text.split(/\s+/)
  const lines: string[] = []
  let cur = ''
  for (const word of words) {
    const test = cur ? `${cur} ${word}` : word
    if (ctx.measureText(test).width <= maxWidth || !cur) {
      cur = test
    } else {
      lines.push(cur)
      cur = word
      if (lines.length === maxLines - 1) break
    }
  }
  if (cur && lines.length < maxLines) lines.push(cur)
  // Cắt bớt + thêm … nếu tràn dòng cuối
  const last = lines[lines.length - 1]
  if (last && ctx.measureText(last).width > maxWidth) {
    let s = last
    while (s.length > 1 && ctx.measureText(`${s}…`).width > maxWidth) s = s.slice(0, -1)
    lines[lines.length - 1] = `${s}…`
  }
  return lines
}

/**
 * Render ảnh chia sẻ bữa ăn ra Blob PNG theo template + tỷ lệ đã chọn.
 * Toàn bộ kích thước tính theo đơn vị u = cạnh ngắn / 1080 để mọi tỷ lệ đồng nhất.
 */
export async function renderShareImage(data: ShareMealData, opts: ShareOptions): Promise<Blob> {
  const { w, h } = RATIO_SIZES[opts.ratio]
  const t = opts.template
  const landscape = w > h
  const u = Math.min(w, h) / 1080

  const canvas = document.createElement('canvas')
  canvas.width  = w
  canvas.height = h
  const ctx = canvas.getContext('2d')!

  // ── Nền gradient ──
  const grad = ctx.createLinearGradient(0, 0, w * 0.4, h)
  grad.addColorStop(0, t.bg[0])
  grad.addColorStop(1, t.bg[1])
  ctx.fillStyle = grad
  ctx.fillRect(0, 0, w, h)

  const pad = 56 * u
  const [foodImg, logoImg] = await Promise.all([
    data.image ? loadImage(data.image) : Promise.resolve(null),
    opts.show.logo ? loadImage('/logo/caloreye_icon_192.png') : Promise.resolve(null),
  ])

  // ── Vùng ảnh món ăn ──
  let imgX = pad, imgY = pad, imgW = w - pad * 2, imgH: number
  let cardX = pad, cardY: number, cardW = w - pad * 2, cardH: number
  const showQR = !!(opts.show.qr && opts.qrUrl)
  const footerH = showQR ? 180 * u : opts.show.logo || opts.show.time ? 90 * u : 24 * u

  if (landscape) {
    imgW = w * 0.46 - pad
    imgH = h - pad * 2
    cardX = w * 0.46 + pad * 0.5
    cardW = w - cardX - pad
    cardY = pad
    cardH = h - pad * 2 - footerH
  } else {
    imgH = h * (opts.ratio === '9:16' ? 0.54 : opts.ratio === '4:5' ? 0.44 : 0.40)
    cardY = imgY + imgH + 28 * u
    cardH = h - cardY - pad - footerH
  }

  const imgR = 40 * u
  if (foodImg) {
    drawCover(ctx, foodImg, imgX, imgY, imgW, imgH, imgR)
    // Overlay nhẹ đáy ảnh cho có chiều sâu
    ctx.save()
    roundRect(ctx, imgX, imgY, imgW, imgH, imgR)
    ctx.clip()
    const ov = ctx.createLinearGradient(0, imgY + imgH * 0.55, 0, imgY + imgH)
    ov.addColorStop(0, 'rgba(0,0,0,0)')
    ov.addColorStop(1, 'rgba(0,0,0,0.35)')
    ctx.fillStyle = ov
    ctx.fillRect(imgX, imgY, imgW, imgH)
    ctx.restore()
  } else {
    // Không có ảnh → panel gradient + emoji lớn
    ctx.save()
    roundRect(ctx, imgX, imgY, imgW, imgH, imgR)
    ctx.fillStyle = t.dark ? 'rgba(255,255,255,0.10)' : 'rgba(24,168,116,0.10)'
    ctx.fill()
    ctx.restore()
    ctx.font = `${200 * u}px ${FONT}`
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    ctx.fillText('🍽️', imgX + imgW / 2, imgY + imgH / 2)
  }

  // Badge điểm dinh dưỡng — góc trên trái ảnh
  if (opts.show.score && data.score != null) {
    const bw = 300 * u
    const bh = 72 * u
    const bx = imgX + 28 * u
    const by = imgY + 28 * u
    roundRect(ctx, bx, by, bw, bh, bh / 2)
    ctx.fillStyle = 'rgba(0,0,0,0.45)'
    ctx.fill()
    ctx.font = `700 ${34 * u}px ${FONT}`
    ctx.fillStyle = '#FFFFFF'
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    ctx.fillText(`🥗 Dinh dưỡng ${data.score}/100`, bx + bw / 2, by + bh / 2 + 2 * u)
    ctx.textAlign = 'left'
    ctx.textBaseline = 'alphabetic'
  }

  // Sticker emoji góc trên phải ảnh
  if (opts.sticker) {
    ctx.font = `${140 * u}px ${FONT}`
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    ctx.save()
    ctx.translate(imgX + imgW - 90 * u, imgY + 90 * u)
    ctx.rotate(0.22)
    ctx.fillText(opts.sticker, 0, 0)
    ctx.restore()
  }

  // ── Card thông tin ──
  ctx.save()
  roundRect(ctx, cardX, cardY, cardW, cardH, 40 * u)
  ctx.fillStyle = t.card
  ctx.shadowColor = 'rgba(0,0,0,0.10)'
  ctx.shadowBlur = 30 * u
  ctx.shadowOffsetY = 10 * u
  ctx.fill()
  ctx.restore()

  const inX = cardX + 44 * u
  const inW = cardW - 88 * u
  let cursorY = cardY + 60 * u
  ctx.textAlign = 'left'
  ctx.textBaseline = 'alphabetic'

  // Tên món (tối đa 2 dòng)
  ctx.font = `700 ${58 * u}px ${FONT}`
  ctx.fillStyle = t.text
  const kcalRoom = opts.show.calories ? 300 * u : 0
  const nameLines = wrapLines(ctx, data.food_name, inW - kcalRoom, 2)
  for (const line of nameLines) {
    ctx.fillText(line, inX, cursorY)
    cursorY += 68 * u
  }

  // Khẩu phần + thời gian
  const subParts = [data.serving, opts.show.time ? data.logged_at : null].filter(Boolean)
  if (subParts.length) {
    ctx.font = `400 ${32 * u}px ${FONT}`
    ctx.fillStyle = t.sub
    ctx.fillText(subParts.join(' · '), inX, cursorY)
    cursorY += 30 * u
  }

  // Calories nổi bật — góc phải trên card
  if (opts.show.calories) {
    ctx.textAlign = 'right'
    ctx.font = `800 ${96 * u}px ${FONT}`
    ctx.fillStyle = t.accent
    ctx.fillText(String(data.calories), cardX + cardW - 44 * u, cardY + 108 * u)
    ctx.font = `600 ${30 * u}px ${FONT}`
    ctx.fillStyle = t.sub
    ctx.fillText('kcal', cardX + cardW - 44 * u, cardY + 148 * u)
    ctx.textAlign = 'left'
  }

  cursorY = Math.max(cursorY, cardY + 190 * u)

  // Macro chips
  if (opts.show.macros) {
    const chips = [
      { label: 'Protein',  value: `${data.protein}g` },
      { label: 'Carb',     value: `${data.carbs}g` },
      { label: 'Chất béo', value: `${data.fat}g` },
    ]
    const gap = 20 * u
    const chipW = (inW - gap * 2) / 3
    const chipH = 118 * u
    chips.forEach((c, i) => {
      const cx = inX + i * (chipW + gap)
      roundRect(ctx, cx, cursorY, chipW, chipH, 24 * u)
      ctx.fillStyle = t.dark ? 'rgba(255,255,255,0.10)' : 'rgba(24,168,116,0.08)'
      ctx.fill()
      ctx.font = `700 ${40 * u}px ${FONT}`
      ctx.fillStyle = t.text
      ctx.textAlign = 'center'
      ctx.fillText(c.value, cx + chipW / 2, cursorY + 52 * u)
      ctx.font = `500 ${26 * u}px ${FONT}`
      ctx.fillStyle = t.sub
      ctx.fillText(c.label, cx + chipW / 2, cursorY + 92 * u)
    })
    ctx.textAlign = 'left'
    cursorY += chipH + 44 * u
  }

  // Thanh tiến độ mục tiêu hôm nay
  if (opts.show.goal && data.goal_percent != null) {
    const pct = Math.max(0, Math.min(data.goal_percent, 100))
    ctx.font = `600 ${30 * u}px ${FONT}`
    ctx.fillStyle = t.sub
    ctx.fillText(`Mục tiêu hôm nay`, inX, cursorY)
    ctx.textAlign = 'right'
    ctx.fillStyle = t.accent
    ctx.fillText(`${data.goal_percent}%`, inX + inW, cursorY)
    ctx.textAlign = 'left'
    cursorY += 22 * u
    const barH = 18 * u
    roundRect(ctx, inX, cursorY, inW, barH, barH / 2)
    ctx.fillStyle = t.dark ? 'rgba(255,255,255,0.15)' : 'rgba(0,0,0,0.08)'
    ctx.fill()
    if (pct > 0) {
      roundRect(ctx, inX, cursorY, Math.max(inW * (pct / 100), barH), barH, barH / 2)
      ctx.fillStyle = t.accent
      ctx.fill()
    }
    cursorY += barH + 44 * u
  }

  // ── Footer: logo + tên app + ngày + QR ──
  const rightEdge = landscape ? cardX + cardW : w - pad - 4 * u

  if (showQR) {
    const qr = encodeQR(opts.qrUrl!, { border: 1 })
    const qs = 150 * u
    const qx = rightEdge - qs
    const qy = h - pad - qs
    roundRect(ctx, qx, qy, qs, qs, 18 * u)
    ctx.fillStyle = '#FFFFFF'
    ctx.fill()
    const inner = qs * 0.88
    const cell = inner / qr.size
    const off = (qs - inner) / 2
    ctx.fillStyle = '#111111'
    for (let r = 0; r < qr.size; r++) {
      for (let c = 0; c < qr.size; c++) {
        // +0.5 tránh khe hở trắng do làm tròn subpixel
        if (qr.data[r][c]) ctx.fillRect(qx + off + c * cell, qy + off + r * cell, cell + 0.5, cell + 0.5)
      }
    }
  }

  if (opts.show.logo || opts.show.time) {
    const fy = landscape ? h - pad - 20 * u : h - pad - 26 * u
    if (opts.show.logo) {
      const size = 56 * u
      let tx = landscape ? cardX : pad + 4 * u
      if (logoImg) {
        ctx.save()
        roundRect(ctx, tx, fy - size + 12 * u, size, size, 14 * u)
        ctx.clip()
        ctx.drawImage(logoImg, tx, fy - size + 12 * u, size, size)
        ctx.restore()
        tx += size + 18 * u
      }
      ctx.font = `700 ${36 * u}px ${FONT}`
      ctx.fillStyle = t.text
      ctx.fillText('CaloEye', tx, fy)
      ctx.font = `400 ${26 * u}px ${FONT}`
      ctx.fillStyle = t.sub
      ctx.fillText('AI Nutrition Tracker', tx, fy + 30 * u)
    }
    if (opts.show.time) {
      ctx.font = `500 ${28 * u}px ${FONT}`
      ctx.fillStyle = t.sub
      ctx.textAlign = 'right'
      // Có QR → ngày né sang trái khối QR
      ctx.fillText(new Date().toLocaleDateString('vi-VN'), rightEdge - (showQR ? 150 * u + 28 * u : 0), fy)
      ctx.textAlign = 'left'
    }
  }

  return new Promise((resolve, reject) => {
    canvas.toBlob(
      (blob) => blob ? resolve(blob) : reject(new Error('Không tạo được ảnh chia sẻ')),
      'image/png',
    )
  })
}
