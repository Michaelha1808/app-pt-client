/**
 * Generates NutriAI PWA icons as valid PNG files using only Node.js built-ins.
 * Produces: icon-192x192.png, icon-512x512.png, apple-touch-icon.png (180px)
 *
 * Design: iOS blue (#007AFF) rounded-rect fill with a white fork+leaf symbol.
 */
import zlib from 'zlib'
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const publicDir = path.join(__dirname, '..', 'public')
const iconsDir  = path.join(publicDir, 'icons')
fs.mkdirSync(iconsDir, { recursive: true })

/* ────────── PNG primitives ────────── */
function crc32(buf) {
  const t = new Uint32Array(256)
  for (let i = 0; i < 256; i++) {
    let c = i
    for (let j = 0; j < 8; j++) c = (c & 1) ? 0xedb88320 ^ (c >>> 1) : c >>> 1
    t[i] = c
  }
  let crc = 0xffffffff
  for (const b of buf) crc = t[(crc ^ b) & 0xff] ^ (crc >>> 8)
  return (crc ^ 0xffffffff) >>> 0
}

function mkChunk(type, data) {
  const tb = Buffer.from(type, 'ascii')
  const lb = Buffer.alloc(4); lb.writeUInt32BE(data.length)
  const cb = Buffer.alloc(4); cb.writeUInt32BE(crc32(Buffer.concat([tb, data])))
  return Buffer.concat([lb, tb, data, cb])
}

function makePng(size) {
  /* ── draw pixels ── */
  // RGBA buffer
  const px = new Uint8Array(size * size * 4)

  const cx  = size / 2
  const cy  = size / 2
  const r   = size * 0.5          // full square coverage
  const rr  = size * 0.22         // corner radius (iOS ~22% of icon size)

  // iOS Blue background with rounded corners
  const BG  = [0, 122, 255, 255]  // #007AFF
  const FG  = [255, 255, 255, 255] // white

  function setPixel(x, y, c) {
    const i = (y * size + x) * 4
    px[i] = c[0]; px[i+1] = c[1]; px[i+2] = c[2]; px[i+3] = c[3]
  }

  // rounded rectangle mask
  function inRRect(x, y) {
    const pad = size * 0.07
    const x0 = pad, y0 = pad, x1 = size - pad - 1, y1 = size - pad - 1
    if (x < x0 || x > x1 || y < y0 || y > y1) return false
    // check corners
    const corners = [[x0+rr,y0+rr],[x1-rr,y0+rr],[x1-rr,y1-rr],[x0+rr,y1-rr]]
    for (const [cx,cy] of corners) {
      if (x < cx - rr || x > cx + rr) continue
      if (y < cy - rr || y > cy + rr) continue
      if ((x-cx)**2 + (y-cy)**2 > rr*rr) return false
    }
    return true
  }

  // paint background (white)
  px.fill(255)
  // paint the rounded rect blue
  for (let y = 0; y < size; y++)
    for (let x = 0; x < size; x++)
      if (inRRect(x, y)) setPixel(x, y, BG)

  // Draw a simple white icon: a bold letter "N" centered
  // We draw it as thick strokes using filled rectangles
  const sc = size / 192  // scale factor (design at 192)
  function rect(x1, y1, w, h, color) {
    const rx = Math.round(x1*sc), ry = Math.round(y1*sc)
    const rw = Math.round(w*sc),  rh = Math.round(h*sc)
    for (let y = ry; y < ry+rh; y++)
      for (let x = rx; x < rx+rw; x++)
        if (x>=0 && x<size && y>=0 && y<size && inRRect(x,y)) setPixel(x,y,color)
  }

  // letter N (offset so it's centered in the icon)
  const lx = 52, ly = 50, lh = 92, lw = 18, diag = 18
  rect(lx,        ly,      lw, lh, FG)  // left bar
  rect(lx+68,     ly,      lw, lh, FG)  // right bar
  // diagonal (approximated with horizontal slices)
  const steps = lh
  for (let i = 0; i < steps; i++) {
    const t  = i / steps
    const dx = lx + lw + Math.round(t * 68)
    rect(dx, ly+i, diag, 1, FG)
  }

  /* ── pack into PNG ── */
  // Convert RGBA to raw filter+RGB rows
  const rowBytes = 1 + size * 3
  const raw = Buffer.alloc(rowBytes * size)
  for (let y = 0; y < size; y++) {
    raw[y * rowBytes] = 0 // filter = None
    for (let x = 0; x < size; x++) {
      const si = (y * size + x) * 4
      const di = y * rowBytes + 1 + x * 3
      raw[di]   = px[si]
      raw[di+1] = px[si+1]
      raw[di+2] = px[si+2]
    }
  }

  const sig = Buffer.from([0x89,0x50,0x4e,0x47,0x0d,0x0a,0x1a,0x0a])

  const ihdr = Buffer.alloc(13)
  ihdr.writeUInt32BE(size, 0)
  ihdr.writeUInt32BE(size, 4)
  ihdr[8] = 8; ihdr[9] = 2  // 8-bit RGB

  return Buffer.concat([
    sig,
    mkChunk('IHDR', ihdr),
    mkChunk('IDAT', zlib.deflateSync(raw, { level: 9 })),
    mkChunk('IEND', Buffer.alloc(0)),
  ])
}

const sizes = [
  { size: 192, file: path.join(iconsDir, 'icon-192x192.png') },
  { size: 512, file: path.join(iconsDir, 'icon-512x512.png') },
  { size: 180, file: path.join(publicDir, 'apple-touch-icon.png') },
]

for (const { size, file } of sizes) {
  fs.writeFileSync(file, makePng(size))
  console.log(`✓ ${path.relative(path.join(__dirname,'..'), file)}  (${size}×${size})`)
}
console.log('Icons generated successfully.')
