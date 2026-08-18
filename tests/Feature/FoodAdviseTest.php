<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /food/advise — sinh lại lời khuyên cho tên/calo món user đã sửa trong Result.vue,
 * không chạy lại nhận diện ảnh. Trước đây không có endpoint này nên sửa tên chỉ đổi state ở
 * FE, lời khuyên hiển thị vẫn "đóng băng" theo tên AI đoán sai ban đầu (bug thật đã báo).
 */
class FoodAdviseTest extends TestCase
{
    use RefreshDatabase;

    /** Buộc chạy callback SSE (test client không tự gọi sendContent()) — nuốt cảnh báo vô hại
     *  từ việc controller chủ động đóng mọi output buffer, xung đột với buffer riêng của
     *  streamedContent() (xem tests/Feature/ChatHistoryControllerTest.php). */
    private function runStream($response): void
    {
        try {
            $response->streamedContent();
        } catch (\Throwable) {
        }
    }

    public function test_advise_requires_food_name_and_calories(): void
    {
        $this->postJson('/api/v1/food/advise', [])->assertStatus(422);
    }

    public function test_advise_rejects_negative_calories(): void
    {
        $this->postJson('/api/v1/food/advise', [
            'food_name' => 'Phở gà', 'calories' => -10,
        ])->assertStatus(422);
    }

    public function test_guest_can_call_advise(): void
    {
        $response = $this->postJson('/api/v1/food/advise', [
            'food_name' => 'Phở gà',
            'calories'  => 400,
            'context'   => ['today_calories' => 800, 'goal' => 1800],
        ]);

        $response->assertStatus(200);
        $this->runStream($response);
    }

    public function test_logged_in_user_gets_streamed_response(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/food/advise', [
            'food_name' => 'Cơm gà nướng',
            'calories'  => 600,
            'context'   => ['today_calories' => 500, 'goal' => 2000],
        ]);

        $response->assertStatus(200);
        $this->runStream($response);
    }
}
