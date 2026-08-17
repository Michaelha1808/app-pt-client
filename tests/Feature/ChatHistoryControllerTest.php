<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatHistoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_chat_without_conversation_id_creates_new_conversation_and_saves_turn(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/chat', [
            'messages' => [['role' => 'user', 'text' => 'Hôm nay ăn gì để giảm cân?']],
        ]);

        $response->assertStatus(200);
        $this->runStream($response);
        $this->assertDatabaseCount('chat_conversations', 1);

        $conversation = ChatConversation::first();
        $this->assertEquals($user->id, $conversation->user_id);
        $this->assertNotNull($conversation->last_message_at);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'role'            => 'user',
            'text'            => 'Hôm nay ăn gì để giảm cân?',
        ]);
    }

    public function test_sending_chat_accepts_long_ai_reply_text_from_history(): void
    {
        // FE gửi lại toàn bộ lịch sử mỗi lượt, kể cả câu trả lời AI trước đó — câu trả lời AI
        // (kế hoạch ăn/tập chi tiết) thường dài hơn 2000 ký tự. Giới hạn quá chặt từng khiến
        // lượt gửi kế tiếp bị 422 ngay ở validate (bug thật gặp trên production).
        $user = User::factory()->create();
        $longAiReply = str_repeat('Gợi ý kế hoạch ăn uống chi tiết cho bạn hôm nay. ', 100); // ~4900 ký tự

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/chat', [
            'messages' => [
                ['role' => 'user', 'text' => 'Lên kế hoạch ăn uống cho tôi'],
                ['role' => 'ai', 'text' => $longAiReply],
                ['role' => 'user', 'text' => 'Gợi ý thêm bài tập nữa'],
            ],
        ]);

        $response->assertStatus(200);
    }

    public function test_sending_chat_with_conversation_id_appends_to_same_conversation(): void
    {
        $user         = User::factory()->create();
        $conversation = $user->chatConversations()->create(['title' => 'Cũ', 'last_message_at' => now()->subHour()]);
        $conversation->messages()->create(['role' => 'user', 'text' => 'Câu hỏi trước']);
        $conversation->messages()->create(['role' => 'ai', 'text' => 'Trả lời trước']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/chat', [
            'conversation_id' => $conversation->id,
            'messages'        => [
                ['role' => 'user', 'text' => 'Câu hỏi trước'],
                ['role' => 'ai', 'text' => 'Trả lời trước'],
                ['role' => 'user', 'text' => 'Câu hỏi mới'],
            ],
        ]);
        $response->assertStatus(200);
        $this->runStream($response);

        // Không hardcode tổng số dòng: AI có thể trả lời thành công (thêm cả lượt "ai") hoặc
        // lỗi mạng (chỉ lượt "user" được lưu) — cả 2 đều hợp lệ, chỉ cần KHÔNG tạo conversation mới
        // và câu hỏi mới nhất phải được nối vào đúng conversation cũ.
        $this->assertDatabaseCount('chat_conversations', 1);
        $this->assertGreaterThanOrEqual(3, $conversation->messages()->count());
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'role'            => 'user',
            'text'            => 'Câu hỏi mới',
        ]);
    }

    public function test_sending_chat_with_foreign_conversation_id_is_forbidden(): void
    {
        $owner  = User::factory()->create();
        $other  = User::factory()->create();
        $convo  = $owner->chatConversations()->create(['last_message_at' => now()]);

        $this->actingAs($other, 'sanctum')->postJson('/api/v1/chat', [
            'conversation_id' => $convo->id,
            'messages'        => [['role' => 'user', 'text' => 'Xin chào']],
        ])->assertStatus(403);
    }

    public function test_guest_chat_does_not_persist_any_conversation(): void
    {
        $this->postJson('/api/v1/chat', [
            'messages' => [['role' => 'user', 'text' => 'Xin chào']],
        ])->assertStatus(200);

        $this->assertDatabaseCount('chat_conversations', 0);
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_index_lists_only_own_conversations_ordered_by_last_message(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();

        $older = $user->chatConversations()->create(['title' => 'Cũ hơn', 'last_message_at' => now()->subDays(2)]);
        $older->messages()->create(['role' => 'ai', 'text' => 'Trả lời cũ']);

        $newer = $user->chatConversations()->create(['title' => 'Mới hơn', 'last_message_at' => now()]);
        $newer->messages()->create(['role' => 'ai', 'text' => 'Trả lời mới']);

        $other->chatConversations()->create(['title' => 'Của người khác', 'last_message_at' => now()])
            ->messages()->create(['role' => 'ai', 'text' => 'Không liên quan']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/chat/conversations');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.preview', 'Trả lời mới')
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_index_preview_uses_last_message_even_when_timestamps_tie(): void
    {
        // 2 tin nhắn 1 lượt (user + ai) thường được ghi trong cùng giây → preview phải lấy
        // đúng tin CUỐI (ai) chứ không phải tin đầu (user) do trùng created_at.
        $user         = User::factory()->create();
        $conversation = $user->chatConversations()->create(['title' => 'Test', 'last_message_at' => now()]);
        $same = now();
        // 'created_at' không có trong $fillable của ChatMessage → set trực tiếp (bypass mass-assignment) để giả lập tie
        $userMsg = $conversation->messages()->create(['role' => 'user', 'text' => 'Câu hỏi']);
        $userMsg->created_at = $same;
        $userMsg->save();
        $aiMsg = $conversation->messages()->create(['role' => 'ai', 'text' => 'Câu trả lời']);
        $aiMsg->created_at = $same;
        $aiMsg->save();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/chat/conversations');

        $response->assertStatus(200)->assertJsonPath('data.0.preview', 'Câu trả lời');
    }

    public function test_index_excludes_empty_conversations(): void
    {
        $user = User::factory()->create();
        $user->chatConversations()->create(['title' => 'Rỗng', 'last_message_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/chat/conversations');

        $response->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_show_returns_full_transcript_for_owner(): void
    {
        $user         = User::factory()->create();
        $conversation = $user->chatConversations()->create(['title' => 'Test', 'last_message_at' => now()]);
        $conversation->messages()->create(['role' => 'user', 'text' => 'Câu hỏi']);
        $conversation->messages()->create(['role' => 'ai', 'text' => 'Trả lời']);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/chat/conversations/{$conversation->id}");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.role', 'user')
            ->assertJsonPath('messages.1.role', 'ai');
    }

    public function test_show_forbidden_for_non_owner(): void
    {
        $owner        = User::factory()->create();
        $other        = User::factory()->create();
        $conversation = $owner->chatConversations()->create(['last_message_at' => now()]);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/v1/chat/conversations/{$conversation->id}")
            ->assertStatus(403);
    }

    public function test_destroy_removes_conversation_and_cascades_messages(): void
    {
        $user         = User::factory()->create();
        $conversation = $user->chatConversations()->create(['last_message_at' => now()]);
        $conversation->messages()->create(['role' => 'user', 'text' => 'Xoá đi']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/chat/conversations/{$conversation->id}")
            ->assertStatus(204);

        $this->assertDatabaseCount('chat_conversations', 0);
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_destroy_forbidden_for_non_owner(): void
    {
        $owner        = User::factory()->create();
        $other        = User::factory()->create();
        $conversation = $owner->chatConversations()->create(['last_message_at' => now()]);

        $this->actingAs($other, 'sanctum')
            ->deleteJson("/api/v1/chat/conversations/{$conversation->id}")
            ->assertStatus(403);

        $this->assertDatabaseCount('chat_conversations', 1);
    }

    /**
     * Buộc chạy callback của StreamedResponse (test client không tự gọi sendContent()).
     * `TestResponse::streamedContent()` mở output buffer riêng để bắt nội dung, nhưng
     * ChatController chủ động đóng MỌI buffer đang mở (để ép flush SSE ngay lập tức khi
     * chạy thật dưới PHP-FPM) — trong test điều đó đóng luôn buffer của streamedContent(),
     * khiến nó cảnh báo "no buffer to delete" khi tự dọn dẹp ở cuối. Vô hại, chỉ nuốt cảnh báo.
     */
    private function runStream($response): void
    {
        try {
            $response->streamedContent();
        } catch (\Throwable) {
            // xem docblock ở trên
        }
    }
}
