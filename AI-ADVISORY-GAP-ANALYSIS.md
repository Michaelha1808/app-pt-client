# Gap Analysis — Tính năng tư vấn AI (CaloEye)

## Context

Đồ án tốt nghiệp cần tính năng tư vấn AI (nutrition/health/workout advice) đủ mạnh
để làm tính năng lõi khi bảo vệ. Tính năng đã tồn tại và chạy được, nhưng cần biết
rõ nó đang làm gì, thiếu gì so với kỳ vọng "trợ lý cá nhân hoá thực sự", và nên bổ
sung gì trong thời gian còn lại trước khi bảo vệ — ưu tiên nâng cấp trên nền hiện có,
không viết lại từ đầu.

Khảo sát dựa trên 2 agent Explore đọc trực tiếp source (`app/Services/ChatService.php`,
`app/Services/MealPlanService.php`, `app/Services/FoodAnalysisService.php`,
`app/Services/PreferenceService.php`, `app/Services/StreakService.php`, routes,
migrations, frontend composables, và các doc `docs/ai-architecture.md`,
`docs/spec-chat-personalization.md`, `docs/spec-food-analysis.md`).

**Phạm vi tài liệu này: chỉ đề xuất, không sửa code.** Codebase hiện ở đúng trạng thái
gốc (đã xác nhận `git status` sạch ngoài 2 file không liên quan có sẵn từ trước:
`CLAUDE.md`, `DEPLOY-DEFENSE.md`). Việc triển khai bất kỳ mục nào ở Phần 3 chỉ bắt đầu
khi có lệnh rõ ràng của user sau khi senior duyệt.

---

## Phần 1 — Hiện trạng

### 1.1 Luồng Chat tư vấn chính (`ChatService` + `ChatController`)

| Thành phần | Chi tiết |
|---|---|
| Trigger | `POST /api/v1/chat` (`routes/api_v1.php:108`) → `ChatController::send()`, throttle `chat` (rate cấu hình qua `Settings`). Không bắt buộc login — guest được trả lời chung chung. |
| Input đưa vào prompt | `ChatService::buildUserContext()` (dòng 32–131): hồ sơ (`birth_year, weight_kg, height_cm, gender, calorie_goal`) → tính BMR (Mifflin-St Jeor)/TDEE bằng PHP; `mealLogs` hôm nay + trung bình 7 ngày; `streak.current_streak`; `waterLogs` hôm nay; `healthActivities` 7 ngày; `WeightService::history(...)['trend']`; `PreferenceService::promptBlock()/habitPromptBlock()`; kế hoạch ngày hiện tại (`mealPlans` scope=daily) + so sánh % hoàn thành hôm qua. |
| Prompt | Lai: template tĩnh lớn (`buildSystemPrompt()`, dòng 304–346: vai trò, giới hạn phạm vi, quy tắc cá nhân hoá, few-shot, chống prompt-injection) + khối context động chèn giữa. |
| Gọi model | Guzzle thô, `streamGenerateContent?alt=sse` (SSE thủ công), model/temperature/key lấy từ `SettingsService` → fallback `config('services.gemini.*')`. Có thêm 1 call phụ `isInScope()` (rẻ, temp 0, max 5 token) để phân loại on/off-topic — **fail-open**: lỗi → coi như `true` (không chặn). |
| Output | Text tự do (SSE `text` delta), không có schema. `suggestActions()` (dòng 248–292) là **PHP keyword-matcher thuần**, không phải AI sinh — chỉ gợi ý 3 nút hành động cố định (`apply_plan/prompt/navigate`). |
| Error handling | `streamReply()`: catch `GuzzleException` → rethrow `RuntimeException`. Controller: catch `\Throwable`, `Log::error`+`report()`, gửi SSE `{"type":"error","message":"Không thể kết nối trợ lý AI..."}`. |
| Lưu trữ hội thoại | `chat_conversations`/`chat_messages` (migration `2026_08_17_*`) — lưu server-side cho user đăng nhập; guest không lưu. `chat_prompt_logs` (`2026_08_16_*`) lưu cả `final_prompt` gửi Gemini — dùng cho `Admin\ChatLogController`. Client giữ tối đa 30 turn, server truncate còn 12 khi build `contents`. |

### 1.2 Meal Plan / Workout (`MealPlanService`)

| Thành phần | Chi tiết |
|---|---|
| Trigger | `POST /api/v1/plan/generate` (auth + throttle `plan-generate`) → `PlanController::generate`; `POST /chat/apply-plan`. |
| Input | Giống context Chat + `data_hash` (sha1 các chỉ số) để phát hiện "kế hoạch cũ". Ném `RuntimeException` nếu hồ sơ thiếu (→ 422). |
| Prompt | Template động theo scope (`dailyPrompt/weeklyPrompt/monthlyPrompt`), heredoc PHP nhúng thẳng trong service. |
| Output | JSON mode (`responseMimeType: application/json`) → `json_decode($raw, true) ?: []`. Schema kỳ vọng: `summary, target_calories, target_macros{...}, meals[], workouts[], tips[]`. **Không có validate schema/type** ngoài kiểm tra `meals` là array. |
| Error handling | `logGeminiFailure()` log status+body (bắt được quota/429/bad-key) nhưng message trả về client vẫn generic. |

### 1.3 Luồng nhận diện món ăn → tư vấn

| Thành phần | Chi tiết |
|---|---|
| `FoodAnalysisService` | `getStructuredData()`/`detectDishes()`: JSON mode, có `normalizeResult()/normalizeDishes()` (coercion, không phải validate chặt). `streamAdvice()`/`streamMealAdvice()`: text tự do qua SSE. |
| `/food/advise-meal` → `FoodController::adviseMeal` (public, throttle `food-analyze`) | Chỉ nhận `dishes[]`, `total_calories`, `context.today_calories/goal` từ request — **không** đọc lịch sử `MealLog`, hồ sơ, dị ứng. Là tính năng tư vấn **rời rạc, một lần**, không liên kết pipeline chung. |
| Kết nối sang Chat | Chỉ gián tiếp: khi món ăn được **log** thật (`MealLog` được tạo), lần chat tiếp theo `ChatService::buildUserContext()` mới "thấy" nó qua tổng hợp `mealLogs`. Bản thân kết quả `advise-meal` (text tư vấn hiển thị 1 lần ở FE) **không được lưu lại và không quay lại nuôi ChatService** trừ khi FE tự chép vào `ai_advice` khi gọi `/food/log`. |
| `StreakService` + `Console/Commands/Notifications/*` | Hoàn toàn **template tĩnh** (mảng `MILESTONE_META` cố định theo mốc streak, tiêu đề/nội dung notification hardcode) — không gọi Gemini, không cá nhân hoá theo dữ liệu người dùng. |

### 1.4 Cá nhân hoá đã có (`PreferenceService`)

- Bảng `user_preferences` (`kind`: allergy/dislike/like/diet/habit, `source`: chat/manual/inferred, cap 50 record/user).
- `promptBlock()` nhúng dị ứng/diet/thích-ghét vào system prompt; `habitPromptBlock()` suy ra từ 30 ngày `mealLogs` (cache 1h): món ăn nhiều nhất, bỏ bữa sáng, ăn khuya, món mới thử.
- `extractFromTurn()`: gate regex rẻ → gọi Gemini JSON mode để trích fact từ hội thoại, có xử lý xung đột (dị ứng luôn override, các loại khác cần xác nhận, không tự áp).
- **Không tìm thấy** field bệnh nền/tình trạng y tế (`health_condition`) ở bất kỳ đâu — spec `ai-architecture.md` có nhắc tới nhưng chưa implement.

### 1.5 Đối chiếu tài liệu spec vs code

- `docs/ai-architecture.md` §12: nhiều mục đã stale — viết trước khi `chat_conversations/chat_messages` được thêm (2026-08-17), và trước khi `PreferenceService` hoàn thiện (spec ghi "chưa implement" nhưng thực tế đã có).
- **Vẫn xác nhận thiếu** theo cả doc lẫn code thực tế: `daily_nutrition` rollup (vẫn `SUM()` live mỗi lần), `nutrition_goals` lịch sử mục tiêu (chỉ có `users.calorie_goal` — 1 giá trị hiện tại, không có lịch sử), rolling summary hội thoại dài hạn, weekly insight job, tầng trừu tượng `LlmClient`/`PromptBuilder` (4 service đang tự lặp lại y hệt boilerplate Guzzle/Gemini).
- `docs/spec-food-analysis.md` ghi nhận **sai provider**: doc mô tả OpenAI GPT-4o-mini nhưng code thực tế gọi Gemini — spec chưa được cập nhật sau khi đổi provider.

---

## Phần 2 — Khoảng trống (senior review)

1. **`advise-meal` là nhánh cụt, không phải một pipeline tư vấn thống nhất.**
   Bằng chứng: `FoodController::adviseMeal` chỉ dùng dữ liệu trong request, không đọc `mealLogs/UserPreference/streak`. Trong khi đó `ChatService::buildUserContext()` mới là nơi thực sự cá nhân hoá sâu. Kết quả: người dùng chụp ảnh xong nhận 1 câu tư vấn "mù" (không biết họ đã ăn gì hôm nay, có dị ứng gì), tách biệt hoàn toàn khỏi trợ lý chat "biết mọi thứ". Đây là điểm dễ bị hỏi khi bảo vệ: "sao tư vấn sau khi chụp ảnh lại khác tư vấn trong chat?"

2. **Output JSON của `MealPlanService` không có schema validation thật sự.**
   Bằng chứng: `json_decode($raw, true) ?: []` rồi chỉ kiểm tra `meals` là array (`planFromConversation()`). Không kiểm tra kiểu dữ liệu của `target_calories`, `macros`, không có min/max hợp lý. Nếu Gemini trả về field thiếu/kiểu sai, lỗi sẽ lan ra tận FE hoặc lưu rác vào `meal_plans.plan`.

3. **Không có cảnh báo/giới hạn an toàn y tế.**
   Bằng chứng trực tiếp: `ChatService.php:337` — quy tắc prompt số 7 nói *"Không mở đầu bằng disclaimer chung chung. Đi thẳng vào tư vấn dựa trên số liệu."* — tức là hệ thống chủ động **loại bỏ** disclaimer thay vì thêm. Không có field `health_condition`/bệnh nền, không có guardrail chặn tư vấn kiểu chẩn đoán y tế. Đây là rủi ro pháp lý/đạo đức thực sự cho một app "tư vấn sức khoẻ" và gần như chắc chắn hội đồng sẽ hỏi.

4. **`isInScope()` fail-open khi lỗi API.**
   Bằng chứng: `catch (\Throwable) { return true; }` — nghĩa là nếu Gemini down/lỗi mạng, bộ lọc phạm vi bị vô hiệu hoá âm thầm, không có log riêng biệt cho case này (chỉ log ở tầng controller cho lỗi stream chính). Không nguy hiểm nhưng là một lỗ hổng UX/an toàn nội dung không ai biết đang tắt.

5. **Streak/Notification hoàn toàn template tĩnh — không "AI" như tên gọi tính năng ngụ ý.**
   Bằng chứng: `MILESTONE_META` là mảng cố định, các lệnh `SendMorningNotifications` hardcode string. Nếu phần thuyết trình gọi đây là "cá nhân hoá bằng AI" thì không đúng thực tế code — cần làm rõ ranh giới khi bảo vệ, hoặc nâng cấp thật.

6. **Không có lịch sử mục tiêu (`nutrition_goals`) — chỉ có giá trị hiện tại.**
   Bằng chứng: `users.calorie_goal` là 1 cột duy nhất, không migration nào tạo bảng lưu lịch sử thay đổi mục tiêu. Điều này khiến tư vấn "tiến độ theo thời gian" (progress narrative) không thể so sánh goal cũ/mới, làm yếu luận điểm "trợ lý theo dõi tiến trình dài hạn".

7. **4 service lặp lại y hệt boilerplate gọi Gemini qua Guzzle thô.**
   Bằng chứng: `ChatService`, `MealPlanService`, `FoodAnalysisService`, `PreferenceService` đều tự viết lại logic gọi `generateContent`/`streamGenerateContent`, xử lý SSE, đọc `SettingsService`. Không sai chức năng nhưng là nợ kỹ thuật — nếu đổi provider (đã từng đổi OpenAI→Gemini, thấy dấu vết ở spec cũ) phải sửa 4 chỗ.

8. **Spec docs không đồng bộ với code** (`spec-food-analysis.md` nói OpenAI, code dùng Gemini; `ai-architecture.md` §12 nói `PreferenceService` "chưa implement" nhưng đã có). Rủi ro: nếu hội đồng đọc doc trước khi hỏi, câu trả lời của sinh viên sẽ vênh với doc.

---

## Phần 3 — Đề xuất

### Nhóm A — Bắt buộc trước khi bảo vệ

**A1. Thêm safety guardrail y tế vào system prompt + UI disclaimer nhẹ.**
- Làm gì: Sửa `buildSystemPrompt()` trong `app/Services/ChatService.php` (khu vực quy tắc, quanh dòng 304–346) để thêm quy tắc: từ chối/chuyển hướng khi người dùng hỏi triệu chứng bệnh, xin chẩn đoán, hoặc liều thuốc — khuyến nghị gặp bác sĩ. Áp dụng tương tự cho `MealPlanService`'s prompt heredocs nếu có phần liên quan tới sức khoẻ.
- Cân nhắc hướng: (a) chỉ sửa prompt (nhanh, rủi ro AI vẫn có thể lệch), (b) sửa prompt + hậu kiểm từ khoá y tế nhạy cảm trước khi stream (chắc hơn nhưng thêm logic). **Chốt: (a) trước, ưu tiên tốc độ — đủ để trình bày trong bảo vệ và giảm rủi ro rõ rệt so với hiện trạng "chủ động bỏ disclaimer".**
- File chạm: `app/Services/ChatService.php`, `app/Services/MealPlanService.php`.
- Công sức: nhỏ (~1–2 giờ, chỉnh prompt + test thủ công vài câu hỏi y tế).

**A2. Nối `advise-meal` vào cùng ngữ cảnh cá nhân hoá với Chat.**
- Làm gì: Trong `FoodController::adviseMeal`, khi có user đăng nhập, lấy thêm `PreferenceService::promptBlock()` (dị ứng) và có thể `mealLogs` hôm nay để tránh tư vấn "mù". Không cần đổi kiến trúc — chỉ truyền thêm context vào `FoodAnalysisService::streamMealAdvice($items, $total, $context)`.
- Cân nhắc hướng: (a) chỉ thêm dị ứng (an toàn, nhanh), (b) thêm toàn bộ context như ChatService (nhất quán hơn nhưng tốn thời gian refactor, trùng logic). **Chốt: (a) — thêm tối thiểu `PreferenceService::promptBlock()`, đủ để chứng minh "tư vấn biết dị ứng người dùng" khi demo, không cần đại tu.**
- File chạm: `app/Http/Controllers/Api/V1/FoodController.php` (`adviseMeal`), `app/Services/FoodAnalysisService.php` (`streamMealAdvice` — thêm tham số context nếu chưa đủ).
- Công sức: nhỏ–vừa (~2–4 giờ).

**A3. Validate tối thiểu output JSON của `MealPlanService`.**
- Làm gì: Sau `json_decode`, kiểm tra các field bắt buộc tồn tại đúng kiểu (`target_calories` là số dương hợp lý, `meals`/`workouts` là mảng, mỗi phần tử có field cần thiết) trước khi lưu vào `meal_plans.plan`; nếu sai, log + trả lỗi rõ ràng thay vì lưu rác.
- Cân nhắc: (a) validate thủ công bằng vài `if`, (b) dùng Laravel `Validator::make` trên mảng đã decode (chuẩn hơn, tận dụng framework có sẵn). **Chốt: (b) — dùng `Validator::make`, khớp pattern Laravel đã dùng ở nơi khác trong repo, không cần thư viện mới.**
- File chạm: `app/Services/MealPlanService.php` (quanh chỗ decode JSON, các hàm `getStructuredPlan()`/`planFromConversation()`).
- Công sức: vừa (~3–5 giờ bao gồm test các case lỗi).

**A4. Đồng bộ lại 2 spec doc quan trọng trước khi in nộp báo cáo.**
- Làm gì: Cập nhật `docs/spec-food-analysis.md` (đổi OpenAI→Gemini cho khớp code) và mục §12 `docs/ai-architecture.md` (đánh dấu `PreferenceService`, `chat_conversations/chat_messages` là "đã có"). Không phải code nhưng bắt buộc vì hội đồng có thể đọc doc.
- File chạm: `docs/spec-food-analysis.md`, `docs/ai-architecture.md`.
- Công sức: nhỏ (~1 giờ, chỉ sửa text).

### Nhóm B — Nếu còn thời gian

**B1. Cá nhân hoá thông báo streak bằng dữ liệu thật (không bắt buộc AI, chỉ cần động).**
- Làm gì: Thay một phần chuỗi tĩnh trong `StreakService::MILESTONE_META`/`Console/Commands/Notifications/*` bằng nội dung có chèn số liệu thật của user (ví dụ: số ngày, streak dài nhất) — không nhất thiết gọi Gemini, giúp tăng cảm giác "cá nhân hoá" khi demo mà chi phí thấp.
- File chạm: `app/Services/StreakService.php`, `app/Console/Commands/Notifications/*`.
- Công sức: nhỏ–vừa (~2–3 giờ).

**B2. Thêm bảng lịch sử mục tiêu dinh dưỡng (`nutrition_goals`) tối giản.**
- Làm gì: Migration mới lưu `(user_id, calorie_goal, changed_at)` mỗi khi `calorie_goal` đổi; dùng để `ChatService`/`MealPlanService` kể được câu chuyện "mục tiêu đã thay đổi thế nào theo thời gian" — tăng chiều sâu cho phần tư vấn tiến độ.
- Cân nhắc: chỉ nên làm nếu còn dư thời gian vì đụng tới migration + hook nơi cập nhật `calorie_goal` (cần tìm nơi user sửa goal, có thể ở `ProfileController` — chưa xác định chính xác, cần grep thêm trước khi làm).
- File chạm: migration mới, model mới, nơi update `calorie_goal` (chưa xác định — cần khảo sát thêm trước khi code).
- Công sức: vừa (~4–6 giờ).

**B3. Rút gọn trùng lặp Gemini client thành 1 helper dùng chung.**
- Làm gì: Tạo 1 class nhỏ (`GeminiClient` hoặc tương tự) gói việc gọi `generateContent`/`streamGenerateContent` + đọc `SettingsService`, rồi cho `ChatService/MealPlanService/FoodAnalysisService/PreferenceService` dùng chung thay vì tự viết Guzzle riêng. Đây là refactor nợ kỹ thuật — không ảnh hưởng tính năng, giúp code sạch hơn khi hội đồng đọc source, nhưng rủi ro phá vỡ luồng đang chạy nếu làm ẩu.
- Cân nhắc: (a) tách helper dùng chung ngay (rủi ro nếu thời gian gấp), (b) để nguyên, chỉ làm nếu A1–A4 xong sớm và còn nhiều thời gian. **Chốt: (b) — đây là hạng mục rủi ro/lợi ích thấp nhất so với các mục còn lại, chỉ nên làm cuối cùng.**
- File chạm: file mới (vd `app/Services/AI/GeminiClient.php`), sửa 4 service để dùng nó.
- Công sức: lớn (~6–10 giờ, có rủi ro regression cần test kỹ toàn bộ luồng chat/plan/food/preference).

---

## Ghi chú số liệu chưa xác định

- Nơi cập nhật `users.calorie_goal` (controller/route chính xác) — chưa xác định, cần grep trước khi làm B2.
- Có `Admin\ChatLogController` xử lý route `admin/chat-logs` nhưng middleware nhóm chính xác (auth+admin) — suy luận từ pattern chung, chưa đọc trực tiếp file để xác nhận.
