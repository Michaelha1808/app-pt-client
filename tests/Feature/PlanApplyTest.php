<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Luồng "xem trước → áp dụng": generate() chỉ tạo bản nháp trong cache, apply() mới lưu DB.
 * Test nạp thẳng nháp vào cache để không phụ thuộc lệnh gọi Gemini thật.
 */
class PlanApplyTest extends TestCase
{
    use RefreshDatabase;

    private function seedDraft(User $user, string $scope = 'daily', string $summary = 'Kế hoạch nháp'): string
    {
        $targetDate = today()->addDay()->toDateString();

        Cache::put("plan_draft:{$user->id}:{$scope}", [
            'target_date' => $targetDate,
            'plan'        => ['summary' => $summary, 'meals' => [], 'workouts' => []],
            'context'     => ['data_hash' => 'hash-abc', 'calorie_goal' => 1800],
            'reasoning'   => 'Vì bạn đang giảm cân nên...',
        ], now()->addMinutes(30));

        return $targetDate;
    }

    public function test_apply_saves_draft_as_active_plan(): void
    {
        $user       = User::factory()->create();
        $targetDate = $this->seedDraft($user);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/plan/apply', ['scope' => 'daily']);

        $response->assertStatus(200)
            ->assertJsonPath('target_date', $targetDate)
            ->assertJsonPath('plan.summary', 'Kế hoạch nháp');

        $this->assertDatabaseHas('meal_plans', [
            'user_id'   => $user->id,
            'scope'     => 'daily',
            'data_hash' => 'hash-abc',
        ]);
        $this->assertSame($targetDate, $user->mealPlans()->first()->target_date->toDateString());
    }

    public function test_apply_without_draft_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/plan/apply', ['scope' => 'daily'])
            ->assertStatus(422);

        $this->assertDatabaseCount('meal_plans', 0);
    }

    public function test_draft_is_consumed_so_applying_twice_fails(): void
    {
        $user = User::factory()->create();
        $this->seedDraft($user);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/plan/apply', ['scope' => 'daily'])->assertStatus(200);
        // Lần 2 không còn nháp → 422, tránh áp dụng lặp ngoài ý muốn
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/plan/apply', ['scope' => 'daily'])->assertStatus(422);

        $this->assertDatabaseCount('meal_plans', 1);
    }

    public function test_draft_of_one_user_cannot_be_applied_by_another(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->seedDraft($owner);

        // Nháp gắn theo user_id trong khoá cache → user khác không thấy gì để áp dụng
        $this->actingAs($other, 'sanctum')
            ->postJson('/api/v1/plan/apply', ['scope' => 'daily'])
            ->assertStatus(422);

        $this->assertDatabaseCount('meal_plans', 0);
    }

    public function test_apply_overwrites_previous_plan_of_same_scope_and_date(): void
    {
        $user       = User::factory()->create();
        $targetDate = $this->seedDraft($user, 'daily', 'Bản mới');

        $user->mealPlans()->create([
            'scope'            => 'daily',
            'target_date'      => $targetDate,
            'plan'             => ['summary' => 'Bản cũ'],
            'context_snapshot' => ['calorie_goal' => 1800],
            'data_hash'        => 'hash-cu',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/plan/apply', ['scope' => 'daily'])
            ->assertStatus(200)
            ->assertJsonPath('plan.summary', 'Bản mới');

        $this->assertDatabaseCount('meal_plans', 1);
    }
}
