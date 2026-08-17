# Spec: Lịch sử chat — Lưu & xem lại hội thoại với AI

> **App:** CaloEye — Nuxt 3 + Vue 3 + Tailwind CSS 4 (iOS-style PWA)
> **Cập nhật lần cuối:** 2026-08-17
> **Trạng thái tổng:** ✅ HOÀN THÀNH — cả 3 Phase implement + verify Docker thật (Postgres) + Playwright UI thật (login → gửi tin → mở Lịch sử → xem 1 hội thoại → xoá, toàn bộ pass). Backend 11/11 test mới pass local PHP 8.5 + SQLite (full suite 75/77, 2 fail sẵn có ở `WeightControllerTest` không liên quan).

---

## 0. Vấn đề hiện tại

Hiện trạng (đã xác minh trong code):

- **Lịch sử chat chỉ tồn tại ở `localStorage` phía client** — [Chat.vue:82-115](../resources/js/pages/Chat.vue#L82-L115), key `caloeye:chat`, lưu theo ngày (`date + messages[]`). Qua ngày mới, đổi thiết bị, xoá cache trình duyệt, hoặc chuyển từ guest → user đăng nhập ([Chat.vue:28-33](../resources/js/pages/Chat.vue#L28-L33)) đều làm mất sạch lịch sử.
- Backend không lưu hội thoại theo cấu trúc đọc lại được. Bảng duy nhất liên quan, `chat_prompt_logs` ([ChatPromptLog.php](../app/Models/ChatPromptLog.php)), là **log audit nội bộ** — mỗi lượt gửi chỉ lưu 1 dòng phẳng (câu hỏi cuối, `final_prompt` — system prompt nội bộ, `reply`), không có `conversation_id`, `user_id` nullable cho khách. Bảng này phục vụ [trang admin `/admin/chat-logs`](../resources/js/pages/admin/ChatLogs.vue) để đối chiếu mức độ cá nhân hoá — **không phải nguồn cho user xem lại chat của chính họ** (và không nên lộ `final_prompt` ra ngoài).
- Không tồn tại route `GET /chat/history` hay tương đương trong `routes/api_v1.php` — chỉ có `POST /chat` (gửi + stream) và `POST /chat/apply-plan`.

**Kết luận:** thiếu ở cả 3 tầng — DB (không có bảng hội thoại), API (không có endpoint đọc lại), UI (không có màn hình). Spec này thiết kế cả 3.

---

## 1. Tổng quan kiến trúc

```
[Nuxt Frontend]                          [Backend API :8000/api/v1]
     │                                            │
     │── POST /chat { messages[], conversation_id? } ──▶ SSE stream
     │         ◀── data: {type:'conversation', id}       (event đầu tiên, TRƯỚC text)
     │         ◀── data: {type:'text', delta}...
     │         ◀── data: [DONE]
     │
     │── GET  /chat/conversations?cursor=...  ──────────▶ { data: [{id, title, last_message_at, preview}], next_cursor }
     │── GET  /chat/conversations/{id}        ──────────▶ { id, title, messages: [{role, text, created_at}] }
     │── DELETE /chat/conversations/{id}      ──────────▶ 204
```

**Nguyên tắc thiết kế:**
- **Giữ nguyên UX "theo ngày" hiện có** (không đổi mental model của user): tin nhắn đầu tiên trong ngày (theo giờ VN) tự tạo 1 `chat_conversations` mới; nút "Làm mới" hiện có ([Chat.vue resetChat](../resources/js/pages/Chat.vue#L118)) cũng kết thúc phiên hiện tại và mở phiên mới — tức 1 ngày có thể có nhiều conversation nếu user bấm làm mới.
- **Chỉ user đăng nhập mới lưu server-side.** Khách (guest) tiếp tục dùng localStorage-only như hiện tại (`user_id` null không có khái niệm "lịch sử của tôi" — đúng hành vi hiện tại, không đổi).
- **V1 chỉ xem lại (read-only), không "tiếp tục" hội thoại cũ.** Tiếp tục 1 thread cũ đặt lại câu hỏi về ngữ cảnh (dữ liệu dinh dưỡng có thể đã đổi) — để Phase sau nếu cần, tránh over-engineer.
- **Tách bạch khỏi `chat_prompt_logs`**: bảng đó vẫn giữ nguyên, phục vụ riêng cho admin/audit (chứa `final_prompt` nội bộ). Bảng mới cho user chỉ chứa `role` + `text` hiển thị được.
- **localStorage vẫn giữ vai trò cache tức thời** cho phiên đang mở (giảm nhấp nháy khi load lại trang), nhưng nguồn sự thật (source of truth) để "xem lại" chuyển sang server.

---

## 2. Data model

### 2.1 Migration: `chat_conversations`

```php
Schema::create('chat_conversations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('title', 120)->nullable(); // auto: 60 ký tự đầu của tin nhắn user đầu tiên
    $table->timestamp('last_message_at')->nullable();
    $table->timestamps();

    $table->index(['user_id', 'last_message_at']);
});
```

### 2.2 Migration: `chat_messages`

```php
Schema::create('chat_messages', function (Blueprint $table) {
    $table->id();
    $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
    $table->enum('role', ['user', 'ai']);
    $table->text('text');
    $table->timestamps();

    $table->index(['conversation_id', 'created_at']);
});
```

Không lưu `final_prompt`/model/in_scope ở đây — những field đó tiếp tục ở riêng trong `chat_prompt_logs` cho mục đích audit.

### 2.3 Models

- `App\Models\ChatConversation` — `belongsTo(User)`, `hasMany(ChatMessage)`, scope `forUser($user)`.
- `App\Models\ChatMessage` — `belongsTo(ChatConversation)`.

---

## 3. API Contract (Backend)

### 3.1 POST `/chat` (sửa endpoint hiện có)

```json
// Request (thêm field mới, optional)
{
  "messages": [{ "role": "user", "text": "..." }],
  "conversation_id": 42          // optional; null/thiếu → tạo conversation mới
}
```

SSE response thêm 1 event **đầu tiên** trước `text`, để FE biết conversation_id vừa tạo (trường hợp gửi lần đầu trong ngày):

```
data: {"type":"conversation","id":42}

data: {"type":"text","delta":"Chào bạn..."}

...
data: [DONE]
```

Luồng xử lý trong `ChatController::send()`:
1. Nếu `$user` (đăng nhập) và có `conversation_id` → load, kiểm tra `conversation.user_id === $user->id` (403 nếu không khớp).
2. Nếu `$user` và không có `conversation_id` → tạo `ChatConversation` mới (`title` = 60 ký tự đầu của `lastUserMessage`).
3. Emit event `conversation` ngay khi có id (trước khi gọi Gemini).
4. Sau khi stream xong (cùng chỗ đang ghi `ChatPromptLog::create`) → ghi thêm 2 dòng vào `chat_messages` (role `user` + role `ai`) và cập nhật `last_message_at` trên conversation.
5. Khách (guest, `$user` null) → **bỏ qua toàn bộ bước trên**, giữ nguyên hành vi hiện tại (không lưu server).

### 3.2 GET `/chat/conversations` (mới, `auth:sanctum`)

```json
// Response 200
{
  "data": [
    {
      "id": 42,
      "title": "Hôm nay ăn gì để giảm cân...",
      "preview": "Mình gợi ý bạn nên ăn ức gà, rau xanh...",   // text của tin nhắn cuối cùng (bất kể role), cắt 100 ký tự
      "message_count": 8,
      "last_message_at": "2026-08-17T10:30:00Z"
    }
  ],
  "meta": { "current_page": 1, "per_page": 20, "total": 3, "last_page": 1 }
}
```

Sắp xếp `last_message_at DESC`. Phân trang chuẩn `paginate()` (query `page`/`per_page`, mặc định 20) — **đã đổi so với thiết kế ban đầu** (cursor pagination) để nhất quán với convention hiện có trong codebase (`Admin\ChatLogController`, `IntegrationController::activities`, …). Conversation chưa có tin nhắn nào (edge case hiếm, ví dụ lỗi giữa chừng) bị loại khỏi danh sách.

### 3.3 GET `/chat/conversations/{id}` (mới, `auth:sanctum`)

```json
// Response 200
{
  "id": 42,
  "title": "Hôm nay ăn gì để giảm cân...",
  "created_at": "2026-08-17T10:00:00Z",
  "messages": [
    { "role": "user", "text": "Hôm nay ăn gì để giảm cân?", "created_at": "2026-08-17T10:00:00Z" },
    { "role": "ai", "text": "Mình gợi ý...", "created_at": "2026-08-17T10:00:05Z" }
  ]
}

// Response 403 — conversation không thuộc về user hiện tại
{ "detail": "Không có quyền truy cập cuộc trò chuyện này" }
```

### 3.4 DELETE `/chat/conversations/{id}` (mới, `auth:sanctum`)

Xoá conversation + cascade messages (quyền riêng tư — user có thể muốn xoá 1 cuộc trò chuyện nhạy cảm). 204 khi thành công, 403 nếu không phải chủ sở hữu.

---

## 4. Frontend

### 4.1 `useChat.ts`

- `send()` nhận thêm tham số `conversationId: number | null` và gửi trong body.
- Thêm callback `onConversation?: (id: number) => void` — parse event `type === 'conversation'`.

### 4.2 `Chat.vue`

- Thêm `ref<number | null> currentConversationId`.
- Khi nhận event `conversation` từ SSE lần đầu trong ngày → lưu vào `currentConversationId` (và cache trong object `caloeye:chat` ở localStorage cùng `messages`, để reload trang trong ngày không tạo trùng conversation).
- `resetChat()` → set `currentConversationId = null` (lượt gửi kế tiếp sẽ tạo conversation mới).
- Thêm nút/icon "Lịch sử" trên header (cạnh nút "Làm mới") → điều hướng `/chat/history`.

### 4.3 Trang mới `/chat/history` (`resources/js/pages/ChatHistory.vue`)

- List các conversation (title + preview + thời gian tương đối "3 giờ trước", "Hôm qua") — gọi `GET /chat/conversations`, infinite scroll bằng cursor.
- Tap vào 1 item → mở view read-only (`/chat/history/{id}`) hiển thị transcript đầy đủ, style bong bóng chat giống `Chat.vue` nhưng không có ô nhập/không gọi AI.
- Swipe-to-delete hoặc nút xoá trên mỗi item → gọi `DELETE /chat/conversations/{id}`, confirm trước khi xoá.
- Empty state khi chưa có cuộc trò chuyện nào.

### 4.4 Composable mới `useChatHistory.ts`

- `listConversations(cursor?)`, `getConversation(id)`, `deleteConversation(id)` — theo pattern các composable khác (`useWeight.ts`, `useQuickLog.ts`).

---

## 5. Danh sách file cần tạo / sửa

**Backend:**
- [x] `database/migrations/2026_08_17_000001_create_chat_conversations_table.php`
- [x] `database/migrations/2026_08_17_000002_create_chat_messages_table.php`
- [x] `app/Models/ChatConversation.php`
- [x] `app/Models/ChatMessage.php`
- [x] `app/Http/Controllers/Api/V1/ChatController.php` — sửa `send()` (tạo/nạp conversation, emit event, ghi `chat_messages`)
- [x] `app/Http/Controllers/Api/V1/ChatHistoryController.php` — mới, `index()`/`show()`/`destroy()`
- [x] `routes/api_v1.php` — thêm 3 route trong group `auth:sanctum`
- [x] `app/Models/User.php` — thêm relation `chatConversations()`

**Frontend:**
- [x] `resources/js/composables/useChat.ts` — thêm `conversationId` + `onConversation`
- [x] `resources/js/composables/useChatHistory.ts` — mới
- [x] `resources/js/pages/Chat.vue` — wire conversation id, nút điều hướng lịch sử
- [x] `resources/js/pages/ChatHistory.vue` — mới (danh sách)
- [x] `resources/js/pages/ChatHistoryDetail.vue` — mới (xem 1 cuộc trò chuyện, read-only)
- [x] `resources/js/types/chat.ts` — thêm type `ChatConversationSummary`/`ChatConversationDetail`, sự kiện SSE `conversation`
- [x] `resources/js/router/index.ts` — đăng ký `/chat/history`, `/chat/history/:id`

**Test:**
- [x] Feature test: gửi `/chat` lần đầu → tạo conversation, gửi lần 2 kèm `conversation_id` → nối vào cùng conversation
- [x] Feature test: `GET /chat/conversations` chỉ trả về của user hiện tại (không rò rỉ của user khác)
- [x] Feature test: `GET/DELETE /chat/conversations/{id}` trả 403 khi không phải chủ sở hữu
- [x] Feature test: guest gửi `/chat` → không tạo bản ghi nào trong `chat_conversations`

---

## 6. Checklist theo Phase

**Phase 1 — Backend (DB + lưu song song)** ✅ 2026-08-17
- [x] 2 migration + 2 model (`ChatConversation`, `ChatMessage`) + relation `User::chatConversations()`
- [x] Sửa `ChatController::send()`: tạo/nạp conversation (validate `conversation_id` optional), emit event `conversation` (id) làm event SSE đầu tiên, ghi `chat_messages` (role user + ai) sau khi stream xong trong try/catch riêng (không chặn response nếu ghi lỗi — cùng pattern với `ChatPromptLog`). Ownership check (`user_id` không khớp → 403) chạy TRƯỚC khi mở stream.
- [x] `ChatHistoryController` (`index`/`show`/`destroy`) + 3 route trong `routes/api_v1.php` (`GET/DELETE /chat/conversations[/{id}]`), ownership check qua `abort_if($conversation->user_id !== $request->user()->id, 403)` — cùng convention với `WeightController`/`NotificationController`. Danh sách dùng `paginate()` chuẩn (page/per_page + `meta{current_page,last_page,total}`), không dùng cursor pagination như spec ban đầu đề xuất (không có tiền lệ cursor pagination trong codebase).
- [x] Feature tests: `tests/Feature/ChatHistoryControllerTest.php` (10 test — tạo conversation mới, nối vào conversation cũ, ownership 403 cho cả `/chat` lẫn `/chat/conversations/*`, guest không lưu, list chỉ của mình + sắp theo `last_message_at`, ẩn conversation rỗng, xem transcript, xoá cascade). Verify local PHP 8.5 + SQLite: 10/10 pass, full suite 74/76 pass (2 fail sẵn có ở `WeightControllerTest`, không liên quan — xem [[project-local-test-setup]]).
- ⚠️ Lưu ý test SSE: `TestResponse::streamedContent()` mở output buffer riêng để bắt nội dung, nhưng `ChatController` chủ động đóng MỌI buffer đang mở (để ép flush SSE ngay khi chạy thật dưới PHP-FPM) — trong test điều đó đóng luôn buffer của `streamedContent()`, gây cảnh báo "no buffer to delete" vô hại khi nó tự dọn ở cuối (test đã bọc try/catch nuốt cảnh báo này, xem `runStream()` trong test file).

**Phase 2 — Frontend tích hợp gửi/nhận** ✅ 2026-08-17
- [x] `useChat.ts`: `send()` nhận thêm `conversationId?`/`onConversation?`, gửi `conversation_id` trong body, parse event SSE `conversation`
- [x] `Chat.vue`: `currentConversationId` lưu kèm trong object localStorage (`caloeye:chat`) cùng `messages`/`date`; set qua callback `onConversation`; reset về `null` khi bấm "Làm mới" hoặc khi chuyển guest → user. Không đổi UX hiện tại (vẫn theo ngày, vẫn có nút Làm mới).

**Phase 3 — UI xem lại lịch sử** ✅ 2026-08-17, verify Docker + Playwright thật
- [x] `useChatHistory.ts` (`fetchConversations`, `fetchMore` phân trang `page`, `getConversation`, `deleteConversation`)
- [x] `ChatHistory.vue` (danh sách card + nút "Xem thêm" theo cùng pattern `Activities.vue`, xoá có confirm, empty-state)
- [x] `ChatHistoryDetail.vue` (xem read-only, tái dùng `formatMessage` markdown tối giản như `Chat.vue`, banner "chỉ xem — không thể tiếp tục nhắn")
- [x] Nút "Lịch sử" trong header `Chat.vue` (chỉ hiện khi đã đăng nhập, `!auth.isGuest`) điều hướng `/chat/history`
- [x] Route `/chat/history` + `/chat/history/:id` (middleware `auth-strict`) trong `resources/js/router/index.ts`
- [x] `vue-tsc --noEmit` sạch, `vite build --mode development` thành công
- [x] **Verify bằng Docker (Postgres) + Playwright thật** (2026-08-17): bật Docker Desktop → `docker compose` tự migrate (entrypoint chạy `migrate --force`) → seed `DemoAccountSeeder` → kịch bản Playwright headless: đăng nhập demo → gửi tin nhắn ở `/chat` → nút "Lịch sử" hiện đúng → danh sách hiển thị đúng preview/thời gian tương đối → mở 1 hội thoại → transcript đúng nội dung đã gửi → quay lại → xoá → item biến mất khỏi danh sách. **Toàn bộ pass.**
- 🐛 **Bug tìm thấy & đã sửa nhờ verify thật** (không bắt được bằng `tsc`/test backend): `ChatHistory.vue` lồng `<button>` (nút xoá) bên trong `<button>` (cả hàng) — HTML không hợp lệ, khiến trình duyệt tự đóng thẻ `<button>` ngoài sớm, làm `event.stopPropagation()` mất tác dụng và DELETE request bị abort do click "leak" ra ngoài. Sửa: đổi hàng ngoài từ `<button>` sang `<div role="button" tabindex="0">`.
- 🐛 **Bug thứ 2**: preview trong danh sách đôi khi lấy nhầm tin nhắn *user* thay vì *AI* — 2 message trong cùng 1 lượt thường có `created_at` trùng giây, `orderBy('created_at')` không đủ để tie-break. Sửa `ChatHistoryController::index()`: thêm `orderByDesc('id')` làm tie-break sau `created_at`. Thêm test `test_index_preview_uses_last_message_even_when_timestamps_tie`.

**Ngoài phạm vi V1 (cân nhắc sau nếu có nhu cầu):**
- Tiếp tục nhắn tin vào 1 conversation cũ (không chỉ xem)
- Tìm kiếm full-text trong lịch sử chat
- Xuất/export lịch sử chat
