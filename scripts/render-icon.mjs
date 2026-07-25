import sharp from 'sharp'
import { readFileSync, writeFileSync } from 'fs'
import { createRequire } from 'module'
const require = createRequire(import.meta.url)
const _p2i = require('png-to-ico')
const pngToIco = _p2i.default || _p2i

// Tạo lại toàn bộ icon app từ vector nhân vật "xin chào".
// Chạy: npm i sharp png-to-ico --no-save && node scripts/render-icon.mjs
const svg = readFileSync('public/logo/caloreye_icon.svg')
const sizes = [48, 72, 96, 144, 192, 512, 1024]

// Rasterize SVG ở độ phân giải cao rồi hạ cỡ → nét căng
const base = await sharp(svg, { density: 288 })
  .resize(1024, 1024, { fit: 'contain', background: { r: 225, g: 245, b: 238, alpha: 1 } })
  .png()
  .toBuffer()

for (const s of sizes) {
  await sharp(base).resize(s, s).png({ compressionLevel: 9 }).toFile(`public/logo/caloreye_icon_${s}.png`)
  console.log('wrote caloreye_icon_' + s + '.png')
}

// favicon.ico đa kích thước (16/32/48/64) từ cùng icon
const icoPngs = await Promise.all([16, 32, 48, 64].map(s => sharp(base).resize(s, s).png().toBuffer()))
const ico = await pngToIco(icoPngs)
writeFileSync('public/favicon.ico', ico)
console.log('wrote favicon.ico')
