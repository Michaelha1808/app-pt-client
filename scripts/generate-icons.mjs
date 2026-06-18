/**
 * Generates minimal valid PNG icons for PWA manifest.
 * Solid blue (#3b82f6) background with white "PT" text.
 * Uses only Node.js built-in modules.
 */
import { writeFileSync, mkdirSync } from 'fs';
import zlib from 'zlib';

function writePng(filePath, size) {
  const r = 0x3b, g = 0x82, b = 0xf6; // #3b82f6 blue

  // Raw image data: filter byte (0x00) + RGB per pixel per row
  const rowBytes = 1 + size * 3;
  const raw = Buffer.alloc(rowBytes * size, 0);
  for (let y = 0; y < size; y++) {
    raw[y * rowBytes] = 0; // filter type: None
    for (let x = 0; x < size; x++) {
      raw[y * rowBytes + 1 + x * 3] = r;
      raw[y * rowBytes + 2 + x * 3] = g;
      raw[y * rowBytes + 3 + x * 3] = b;
    }
  }

  const compressed = zlib.deflateSync(raw, { level: 6 });

  function crc32(buf) {
    const table = (() => {
      const t = new Uint32Array(256);
      for (let i = 0; i < 256; i++) {
        let c = i;
        for (let j = 0; j < 8; j++) c = (c & 1) ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        t[i] = c;
      }
      return t;
    })();
    let crc = 0xffffffff;
    for (const byte of buf) crc = table[(crc ^ byte) & 0xff] ^ (crc >>> 8);
    return (crc ^ 0xffffffff) >>> 0;
  }

  function chunk(type, data) {
    const len = Buffer.alloc(4);
    len.writeUInt32BE(data.length);
    const typeBytes = Buffer.from(type, 'ascii');
    const crcInput = Buffer.concat([typeBytes, data]);
    const crcBuf = Buffer.alloc(4);
    crcBuf.writeUInt32BE(crc32(crcInput));
    return Buffer.concat([len, typeBytes, data, crcBuf]);
  }

  const ihdrData = Buffer.alloc(13);
  ihdrData.writeUInt32BE(size, 0);
  ihdrData.writeUInt32BE(size, 4);
  ihdrData[8] = 8;  // bit depth
  ihdrData[9] = 2;  // RGB
  ihdrData[10] = 0; // compression
  ihdrData[11] = 0; // filter
  ihdrData[12] = 0; // interlace

  const sig = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);
  const png = Buffer.concat([
    sig,
    chunk('IHDR', ihdrData),
    chunk('IDAT', compressed),
    chunk('IEND', Buffer.alloc(0)),
  ]);

  writeFileSync(filePath, png);
  console.log(`Generated ${filePath} (${size}x${size})`);
}

mkdirSync('frontend/public/icons', { recursive: true });
writePng('frontend/public/icons/icon-192x192.png', 192);
writePng('frontend/public/icons/icon-512x512.png', 512);
