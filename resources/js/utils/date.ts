/**
 * Ngày hôm nay theo giờ LOCAL của máy, dạng YYYY-MM-DD.
 * KHÔNG dùng new Date().toISOString().slice(0,10) — đó là ngày UTC,
 * sai từ 00:00–07:00 giờ Việt Nam (lệch về hôm trước).
 */
export function localDateStr(d: Date = new Date()): string {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}
