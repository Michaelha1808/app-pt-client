# Spec: Chia sẻ bữa ăn lên mạng xã hội (Share Meal)

> Trạng thái: 🟢 Đã implement (client-only, không cần API mới) — 2026-07-12

## Mục lục

1. [Mục tiêu](#1-mục-tiêu)
2. [Phạm vi & vị trí chức năng](#2-phạm-vi--vị-trí-chức-năng)
3. [Luồng UX](#3-luồng-ux)
4. [Kiến trúc & file list](#4-kiến-trúc--file-list)
5. [Tạo ảnh chia sẻ (canvas)](#5-tạo-ảnh-chia-sẻ-canvas)
6. [Chia sẻ nhanh theo mạng](#6-chia-sẻ-nhanh-theo-mạng)
7. [Lưu mẫu (persistence)](#7-lưu-mẫu-persistence)
8. [Checklist](#8-checklist)

## 1. Mục tiêu

Cho phép người dùng chia sẻ bữa ăn vừa ghi lại lên mạng xã hội với ảnh social card đẹp, caption/hashtag tuỳ chỉnh, thao tác nhanh — tăng tính lan truyền của app.

## 2. Phạm vi & vị trí chức năng

- **Result.vue** (`/result`): sau khi bấm "Xác nhận & Lưu" thành công → thanh nút dưới đổi thành **[Về trang chủ] [📤 Chia sẻ bữa ăn]** (không tự navigate về home như trước).
- **History.vue** (`/history`): mỗi bữa ăn trong danh sách có icon 📤 (cạnh sao ⭐) mở sheet chia sẻ.
- Hoàn toàn client-side: canvas tạo ảnh + Web Share API + web intent. Không có endpoint backend mới.

## 3. Luồng UX

Bottom sheet (`ShareMealSheet.vue`, z-70, max 430px, slideUpSheet) gồm từ trên xuống:

1. **Preview social card live** — cập nhật realtime theo mọi tuỳ chọn: ảnh món (fallback emoji 🍽️), badge điểm dinh dưỡng, sticker, tên món, khẩu phần + thời gian, kcal nổi bật, 3 chip macro, thanh % mục tiêu hôm nay, footer logo CaloEye + ngày.
2. **Chọn nền** — 6 template: Minimal White / Healthy Green / Dark Mode / Gradient / Fitness / Modern Card (swatch gradient, scroll ngang).
3. **Tỷ lệ ảnh** — segmented 1:1, 4:5, 9:16 (Story), 16:9.
4. **Hiển thị** — 6 toggle chip: Calo, Macro, Mục tiêu, Điểm DD, Logo, Thời gian (áp cả vào ảnh lẫn caption mặc định).
5. **Sticker** — none + 💪🔥🥗😋🏆❤️ (vẽ xoay nhẹ góc phải trên ảnh).
6. **Caption** — textarea + nút "↻ Tạo lại" (sinh từ dữ liệu bữa) + "💾 Lưu mẫu" + hàng emoji chèn nhanh + danh sách caption đã lưu (chọn lại / xoá).
7. **Hashtag** — chip toggle từ danh sách đã lưu + input thêm mới.
8. **Chia sẻ đến** — grid: Facebook, Instagram, Threads, X, TikTok, Messenger, Zalo, Telegram, WhatsApp, Sao chép, Tải ảnh + nút chính "Chia sẻ ngay" (native share sheet).

## 4. Kiến trúc & file list

| File | Vai trò |
|------|---------|
| `resources/js/types/share.ts` | `ShareMealData`, `ShareVisibility`, `ShareTemplate`, `SHARE_TEMPLATES` (6 nền), `RATIO_SIZES` |
| `resources/js/utils/shareImage.ts` | `renderShareImage(data, opts)` → Blob PNG (canvas, mọi tỷ lệ/template) |
| `resources/js/composables/useShareMeal.ts` | Prefs singleton (localStorage `share_meal_prefs`), `SHARE_NETWORKS`, `nutritionScore()`, `buildDefaultCaption()`, actions: shareImageNative / shareTo / downloadImage / copyText / saveCaption / saveHashtag |
| `resources/js/components/share/ShareMealSheet.vue` | Bottom sheet UI, preview live, nhận `v-model:open` + prop `meal: ShareMealData` |
| `resources/js/pages/Result.vue` | Tích hợp sau lưu: toast "Đã lưu bữa ăn! 🎉" + bar [Về trang chủ]/[Chia sẻ] |
| `resources/js/pages/History.vue` | Icon 📤 per meal → `shareLog(meal)` build `ShareMealData` từ `MealLogEntry` |

**Điểm dinh dưỡng**: heuristic client-side `nutritionScore()` — độ lệch macro so với tỷ lệ lý tưởng P25/C50/F25 năng lượng, clamp 40–99. Không gọi AI.

## 5. Tạo ảnh chia sẻ (canvas)

- Kích thước: 1:1 → 1080×1080, 4:5 → 1080×1350, 9:16 → 1080×1920, 16:9 → 1920×1080. Mọi số đo scale theo `u = cạnh ngắn / 1080`.
- Layout dọc: gradient nền → ảnh món (cover, bo góc, overlay tối 35% đáy; 9:16 ảnh chiếm 54% chiều cao) → card thông tin → footer logo + ngày + QR. Layout 16:9: ảnh trái ~46%, card phải.
- **QR code** (toggle 🔳, mặc định tắt): thư viện `uqr` (zero-dep), encode URL app, box trắng bo góc ở góc phải dưới; ngày tự né sang trái. Preview dùng `renderSVG` data URI.
- Ảnh món: dataURL (vừa chụp) hoặc `image_url` (crossOrigin anonymous); load fail → panel emoji 🍽️.
- Logo: `/logo/caloreye_icon_192.png` (same-origin).
- Fallback `ctx.roundRect` thủ công cho WebView cũ.

## 6. Chia sẻ nhanh theo mạng

| Mạng | Cơ chế |
|------|--------|
| Facebook, X, Threads, Telegram, WhatsApp | Web intent URL (mở tab mới, kèm caption+hashtag+link app) |
| Messenger | `fb-messenger://share` scheme |
| Instagram, TikTok, Zalo | Không có web intent → `navigator.share({files})` (native sheet); fallback tải ảnh về + toast hướng dẫn |
| Sao chép | Clipboard: caption + hashtag + `window.location.origin` |
| Tải ảnh | Blob → `<a download>` |

## 7. Lưu mẫu (persistence)

localStorage key `share_meal_prefs` (singleton ref, watch deep → save):

```ts
{ templateId, ratio, show: {calories, macros, goal, logo, time, score}, sticker,
  savedCaptions: string[] /* max 5 */, savedHashtags: string[] /* max 12, mặc định #CaloEye #HealthyEating #MealTracker */ }
```

## 8. Checklist

- ✅ Types + 6 template + kích thước tỷ lệ
- ✅ Canvas renderer (4 tỷ lệ, sticker, badge điểm DD, goal bar, footer logo)
- ✅ Composable prefs + share actions + nutritionScore
- ✅ ShareMealSheet: preview live, template/ratio/toggle/sticker/caption/hashtag/share grid
- ✅ Tích hợp Result.vue (sau lưu) + History.vue (per meal)
- ✅ QR code trong ảnh (uqr) — toggle 🔳, canvas + preview
- ✅ `vue-tsc --noEmit` pass + `vite build` pass
- ✅ Verify Playwright local (2026-07-12): mở sheet, đổi template/tỷ lệ/sticker/QR, preview realtime đúng, tải ảnh 9:16 + 16:9 thành công (~1.3MB PNG, đủ kcal/macro/goal/badge/sticker/logo/QR), không lỗi JS. Lưu ý build: asset trong `public/` phải bind động (`:src="logoUrl"`), `src` tĩnh sẽ bị Vite compile thành module import → build fail
- 🔴 Verify trên thiết bị thật: native share với file trên iOS Safari PWA (iOS ≥ 15 mới hỗ trợ share files), fallback download trên desktop
- 🔴 (Tuỳ chọn tương lai) chia sẻ video TikTok, trang public landing riêng cho link chia sẻ (hiện link = origin)
