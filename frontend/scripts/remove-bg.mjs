/**
 * Pure Node.js PNG background remover — no external dependencies.
 * Reads a PNG, makes near-white pixels transparent, writes result PNG with alpha.
 */
import { readFileSync, writeFileSync } from 'fs'
import { inflateSync, deflateSync } from 'zlib'

const SIG = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10])

// ── CRC-32 table ──────────────────────────────────────────────────────────────
const CRC_TABLE = (() => {
  const t = new Uint32Array(256)
  for (let n = 0; n < 256; n++) {
    let c = n
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
    t[n] = c
  }
  return t
})()

function crc32(buf) {
  let c = 0xffffffff
  for (let i = 0; i < buf.length; i++) c = CRC_TABLE[(c ^ buf[i]) & 0xff] ^ (c >>> 8)
  return (c ^ 0xffffffff) >>> 0
}

function makeChunk(type, data) {
  const typeBuf = Buffer.from(type, 'ascii')
  const lenBuf = Buffer.alloc(4)
  lenBuf.writeUInt32BE(data.length)
  const crcBuf = Buffer.alloc(4)
  crcBuf.writeUInt32BE(crc32(Buffer.concat([typeBuf, data])))
  return Buffer.concat([lenBuf, typeBuf, data, crcBuf])
}

// ── PNG chunk parser ───────────────────────────────────────────────────────────
function parseChunks(buf) {
  const chunks = []
  let p = 8
  while (p < buf.length) {
    const len = buf.readUInt32BE(p); p += 4
    const type = buf.toString('ascii', p, p + 4); p += 4
    const data = buf.subarray(p, p + len); p += len + 4 // skip crc
    chunks.push({ type, data: Buffer.from(data) })
  }
  return chunks
}

// ── PNG filter reconstruction (per-scanline) ───────────────────────────────────
function paethPredictor(a, b, c) {
  const p = a + b - c
  const pa = Math.abs(p - a), pb = Math.abs(p - b), pc = Math.abs(p - c)
  return pa <= pb && pa <= pc ? a : pb <= pc ? b : c
}

function unfilter(raw, width, bpp) {
  const stride = width * bpp + 1
  const out = Buffer.alloc(width * bpp * (raw.length / stride | 0))
  let outPos = 0
  for (let y = 0; y < raw.length / stride; y++) {
    const base = y * stride
    const filter = raw[base]
    const row = raw.subarray(base + 1, base + 1 + width * bpp)
    const prev = y > 0 ? out.subarray((y - 1) * width * bpp, y * width * bpp) : Buffer.alloc(width * bpp)
    for (let x = 0; x < width * bpp; x++) {
      const a = x >= bpp ? out[outPos - bpp] : 0
      const b = prev[x]
      const c = x >= bpp ? prev[x - bpp] : 0
      let v = row[x]
      if (filter === 1) v = (v + a) & 0xff
      else if (filter === 2) v = (v + b) & 0xff
      else if (filter === 3) v = (v + ((a + b) >> 1)) & 0xff
      else if (filter === 4) v = (v + paethPredictor(a, b, c)) & 0xff
      out[outPos++] = v
    }
  }
  return out
}

function filterNone(pixels, width, bpp) {
  const stride = width * bpp + 1
  const out = Buffer.alloc(stride * (pixels.length / (width * bpp)))
  const rows = pixels.length / (width * bpp)
  for (let y = 0; y < rows; y++) {
    out[y * stride] = 0 // filter type None
    pixels.copy(out, y * stride + 1, y * width * bpp, (y + 1) * width * bpp)
  }
  return out
}

// ── Main ──────────────────────────────────────────────────────────────────────
const [,, inFile, outFile] = process.argv
if (!inFile || !outFile) {
  console.error('Usage: node remove-bg.mjs <input.png> <output.png>')
  process.exit(1)
}

const buf = readFileSync(inFile)
if (!buf.subarray(0, 8).equals(SIG)) throw new Error('Not a valid PNG file')

const chunks = parseChunks(buf)
const ihdr = chunks.find(c => c.type === 'IHDR').data

const width     = ihdr.readUInt32BE(0)
const height    = ihdr.readUInt32BE(4)
const bitDepth  = ihdr[8]
const colorType = ihdr[9]

if (bitDepth !== 8) throw new Error(`Only 8-bit PNG supported (got ${bitDepth}-bit)`)
// colorType: 2=RGB, 6=RGBA
if (colorType !== 2 && colorType !== 6) throw new Error(`Only RGB/RGBA PNG supported (colorType=${colorType})`)

const srcBpp = colorType === 6 ? 4 : 3

// Decompress and unfilter
const idatData = Buffer.concat(chunks.filter(c => c.type === 'IDAT').map(c => c.data))
const raw = inflateSync(idatData)
const pixels = unfilter(raw, width, srcBpp)

// Process: convert to RGBA, make white-ish pixels transparent
const rgba = Buffer.alloc(width * height * 4)
for (let i = 0; i < width * height; i++) {
  const r = pixels[i * srcBpp + 0]
  const g = pixels[i * srcBpp + 1]
  const b = pixels[i * srcBpp + 2]
  const a = srcBpp === 4 ? pixels[i * srcBpp + 3] : 255

  rgba[i * 4 + 0] = r
  rgba[i * 4 + 1] = g
  rgba[i * 4 + 2] = b

  // Measure "whiteness": all channels high AND similar to each other
  const minC = Math.min(r, g, b)
  const maxC = Math.max(r, g, b)
  const spread = maxC - minC // low spread = gray/white, high spread = colored

  if (minC > 210 && spread < 40) {
    // Near-white or light-gray → fade to transparent proportionally
    const alpha = Math.max(0, Math.round((255 - minC) * (255 / 45)))
    rgba[i * 4 + 3] = Math.min(a, Math.min(255, alpha))
  } else {
    rgba[i * 4 + 3] = a
  }
}

// Build output PNG (RGBA, colorType=6)
const newIhdr = Buffer.alloc(13)
newIhdr.writeUInt32BE(width, 0)
newIhdr.writeUInt32BE(height, 4)
newIhdr[8] = 8   // bit depth
newIhdr[9] = 6   // RGBA
newIhdr[10] = 0  // compression
newIhdr[11] = 0  // filter
newIhdr[12] = 0  // interlace

const filtered = filterNone(rgba, width, 4)
const compressed = deflateSync(filtered, { level: 9 })

const outBuf = Buffer.concat([
  SIG,
  makeChunk('IHDR', newIhdr),
  makeChunk('IDAT', compressed),
  makeChunk('IEND', Buffer.alloc(0)),
])

writeFileSync(outFile, outBuf)
console.log(`✅  Done: ${outFile}  (${width}×${height}, RGBA)`)
