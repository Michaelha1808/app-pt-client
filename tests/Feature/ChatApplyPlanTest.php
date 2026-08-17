<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatApplyPlanTest extends TestCase
{
    use RefreshDatabase;

    private function fullProfileUser(): User
    {
        return User::factory()->create([
            'birth_year'   => 1998,
            'height_cm'    => 165,
            'weight_kg'    => 60,
            'gender'       => 'female',
            'calorie_goal' => 1800,
        ]);
    }

    private array $conversation = [
        ['role' => 'user', 'text' => 'Gợi ý bữa sáng, trưa, tối cho tôi'],
        ['role' => 'ai', 'text' => 'Bữa sáng: phở gà. Bữa trưa: cơm gạo lứt ức gà. Bữa tối: salad cá hồi.'],
    ];

    public function test_apply_plan_without_target_date_defaults_to_today(): void
    {
        $user = $this->fullProfileUser();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/chat/apply-plan', [
            'messages' => $this->conversation,
        ]);

        $response->assertStatus(200)->assertJsonPath('target_date', today()->toDateString());
        $this->assertDatabaseHas('meal_plans', [
            'user_id'     => $user->id,
            'scope'       => 'daily',
            'target_date' => today()->toDateString(),
        ]);
    }

    public function test_apply_plan_with_future_target_date_saves_to_that_date(): void
    {
        $user   = $this->fullProfileUser();
        $future = today()->addDays(3)->toDateString();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/chat/apply-plan', [
            'messages'    => $this->conversation,
            'target_date' => $future,
        ]);

        $response->assertStatus(200)->assertJsonPath('target_date', $future);
        $this->assertDatabaseHas('meal_plans', [
            'user_id'     => $user->id,
            'scope'       => 'daily',
            'target_date' => $future,
        ]);
        // Không được đụng vào plan của hôm nay
        $this->assertDatabaseMissing('meal_plans', [
            'user_id'     => $user->id,
            'target_date' => today()->toDateString(),
        ]);
    }

    public function test_apply_plan_with_past_target_date_is_rejected(): void
    {
        $user = $this->fullProfileUser();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/chat/apply-plan', [
            'messages'    => $this->conversation,
            'target_date' => today()->subDay()->toDateString(),
        ])->assertStatus(422);

        $this->assertDatabaseCount('meal_plans', 0);
    }

    public function test_apply_plan_twice_for_same_future_date_overwrites_not_duplicates(): void
    {
        $user   = $this->fullProfileUser();
        $future = today()->addDay()->toDateString();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/chat/apply-plan', [
            'messages' => $this->conversation, 'target_date' => $future,
        ])->assertStatus(200);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/chat/apply-plan', [
            'messages' => $this->conversation, 'target_date' => $future,
        ])->assertStatus(200);

        $this->assertDatabaseCount('meal_plans', 1);
    }
}
