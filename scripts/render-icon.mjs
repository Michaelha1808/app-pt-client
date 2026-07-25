import sharp from 'sharp'
import { readFileSync } from 'fs'

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
