<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FoodAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /food/estimate — ước tính lại calo/macro theo tên món user vừa sửa.
 *
 * Trước đây sửa tên món AI đoán sai chỉ đổi chữ hiển thị và sinh lại lời khuyên; con số
 * calo/macro vẫn của món cũ nên nhật ký lưu sai hoàn toàn (sửa "Phở bò" thành "Bánh xèo"
 * mà vẫn ghi 450 kcal của phở). Endpoint này trả về số mới để FE áp vào form trước khi
 * gọi /food/advise.
 *
 * Bind mock FoodAnalysisService thay vì gọi Gemini thật — cùng lý do với
 * StreakNotificationPersonalizationTest: test phải chạy được khi không có API key.
 */
class FoodEstimateTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE = [
        'serving'  => '1 cái (~200g)',
        'calories' => 620,
        'protein'  => 18,
        'carbs'    => 55,
        'fat'      => 34,
        'sodium'   => 980,
    ];

    private function fakeService(): \Mockery\MockInterface
    {
        $mock = \Mockery::mock(FoodAnalysisService::class);
        $this->app->bind(FoodAnalysisService::class, fn () => $mock);
        return $mock;
    }

    public function test_estimate_requires_food_name(): void
    {
        $this->postJson('/api/v1/food/estimate', [])->assertStatus(422);
    }

    public function test_estimate_rejects_too_long_food_name(): void
    {
        $this->postJson('/api/v1/food/estimate', [
            'food_name' => str_repeat('a', 201),
        ])->assertStatus(422);
    }

    public function test_estimate_rejects_too_long_serving(): void
    {
        $this->postJson('/api/v1/food/estimate', [
            'food_name' => 'Bánh xèo tôm thịt',
            'serving'   => str_repeat('a', 201),
        ])->assertStatus(422);
    }

    /** Khách (chưa đăng nhập) vẫn sửa được món ở màn kết quả → phải gọi được endpoint này */
    public function test_guest_can_call_estimate(): void
    {
        $this->fakeService()
            ->shouldReceive('estimateNutrition')
            ->once()
            ->with('Bánh xèo tôm thịt', '1 cái (~200g)', null)
            ->andReturn(self::FAKE);

        $this->postJson('/api/v1/food/estimate', [
            'food_name' => 'Bánh xèo tôm thịt',
            'serving'   => '1 cái (~200g)',
        ])->assertStatus(200)
          ->assertJson(self::FAKE);
    }

    /** Màn Chọn món ước tính cho 1 ĐƠN VỊ (nhân với stepper số lượng sau) */
    public function test_estimate_passes_unit_label_for_multi_dish_screen(): void
    {
        $user = User::factory()->create();

        $this->fakeService()
            ->shouldReceive('estimateNutrition')
            ->once()
            ->with('Phở gà', null, 'tô')
            ->andReturn(self::FAKE);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/food/estimate', [
            'food_name'  => 'Phở gà',
            'unit_label' => 'tô',
        ])->assertStatus(200);
    }

    /** Gemini lỗi → 502 kèm thông báo tiếng Việt, không phải 500 trần */
    public function test_estimate_returns_502_when_ai_fails(): void
    {
        $this->fakeService()
            ->shouldReceive('estimateNutrition')
            ->once()
            ->andThrow(new \RuntimeException('Gemini API error'));

        $this->postJson('/api/v1/food/estimate', [
            'food_name' => 'Bánh xèo tôm thịt',
        ])->assertStatus(502)
          ->assertJsonStructure(['message']);
    }
}
