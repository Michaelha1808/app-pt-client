<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeightControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_creates_entry_and_syncs_current_weight(): void
    {
        $user = User::factory()->create(['weight_kg' => 70]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/weight/log', ['weight_kg' => 68.5]);

        $response->assertStatus(201)
            ->assertJsonPath('current_weight_kg', 68.5)
            ->assertJsonPath('entry.weight_kg', 68.5);

        $this->assertEquals(68.5, (float) $user->fresh()->weight_kg);
        $this->assertDatabaseCount('weight_logs', 1);
    }

    public function test_logging_same_day_twice_upserts_instead_of_duplicating(): void
    {
        $user = User::factory()->create(['weight_kg' => 70]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/weight/log', ['weight_kg' => 69])
            ->assertStatus(201);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/weight/log', ['weight_kg' => 68.2])
            ->assertStatus(200);

        $response->assertJsonPath('current_weight_kg', 68.2);
        $this->assertDatabaseCount('weight_logs', 1);
        $this->assertEquals(68.2, (float) $user->fresh()->weight_kg);
    }

    public function test_logging_a_past_date_does_not_override_current_weight(): void
    {
        $user = User::factory()->create(['weight_kg' => 70]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/weight/log', [
            'weight_kg'   => 65,
            'logged_date' => today()->toDateString(),
        ])->assertStatus(201);

        // Ghi 1 ngày cũ hơn — không được ghi đè cân nặng hiện tại (mới nhất theo ngày)
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/weight/log', [
            'weight_kg'   => 80,
            'logged_date' => today()->subDays(5)->toDateString(),
        ])->assertStatus(201);

        $this->assertEquals(65, (float) $user->fresh()->weight_kg);
    }

    public function test_future_date_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/weight/log', [
            'weight_kg'   => 65,
            'logged_date' => today()->addDay()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_history_returns_trend_and_entries_within_range(): void
    {
        $user = User::factory()->create(['weight_kg' => 64]);

        $user->weightLogs()->create(['weight_kg' => 66, 'logged_date' => today()->subDays(10)]);
        $user->weightLogs()->create(['weight_kg' => 65, 'logged_date' => today()->subDays(5)]);
        $user->weightLogs()->create(['weight_kg' => 64, 'logged_date' => today()]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/weight/history?range=30');

        $response->assertOk()->assertJsonCount(3, 'entries');
        $this->assertEquals(66.0, $response->json('trend.start_weight_kg'));
        $this->assertEquals(64.0, $response->json('trend.current_weight_kg'));
        $this->assertEquals(-2.0, $response->json('trend.delta_kg'));
    }

    public function test_delete_resyncs_current_weight_to_remaining_latest_entry(): void
    {
        $user = User::factory()->create(['weight_kg' => 64]);

        $older = $user->weightLogs()->create(['weight_kg' => 66, 'logged_date' => today()->subDays(5)]);
        $latest = $user->weightLogs()->create(['weight_kg' => 64, 'logged_date' => today()]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/weight/log/{$latest->id}")
            ->assertOk();

        $this->assertEquals(66.0, $response->json('current_weight_kg'));
        $this->assertEquals(66, (float) $user->fresh()->weight_kg);
        $this->assertDatabaseHas('weight_logs', ['id' => $older->id]);
        $this->assertDatabaseMissing('weight_logs', ['id' => $latest->id]);
    }

    public function test_cannot_delete_another_users_entry(): void
    {
        $owner  = User::factory()->create();
        $other  = User::factory()->create();
        $entry  = $owner->weightLogs()->create(['weight_kg' => 60, 'logged_date' => today()]);

        $this->actingAs($other, 'sanctum')
            ->deleteJson("/api/v1/weight/log/{$entry->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('weight_logs', ['id' => $entry->id]);
    }

    public function test_updating_profile_weight_upserts_todays_weight_log(): void
    {
        $user = User::factory()->create(['weight_kg' => 70]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/user/profile', ['weight_kg' => 69.3])
            ->assertOk();

        // Không dùng assertDatabaseHas cho logged_date: cast 'date' lưu xuống SQL kèm giờ
        // ("2026-08-18 00:00:00"), so khớp chuỗi thô với toDateString() ("2026-08-18", không
        // giờ) sẽ luôn trật dù dữ liệu đúng — đọc qua model (tự áp cast) để so cho đúng.
        $entry = $user->weightLogs()->whereDate('logged_date', today())->first();
        $this->assertNotNull($entry, 'Không tìm thấy weight_log của hôm nay');
        $this->assertSame(today()->toDateString(), $entry->logged_date->toDateString());
        $this->assertEquals(69.3, $entry->weight_kg);
    }

    public function test_apply_goal_updates_calorie_goal(): void
    {
        $user = User::factory()->create(['calorie_goal' => 2000]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/weight/apply-goal', ['calorie_goal' => 1800])
            ->assertOk()
            ->assertJsonPath('calorie_goal', 1800);

        $this->assertEquals(1800, $user->fresh()->calorie_goal);
        $this->assertDatabaseHas('usage_events', ['user_id' => $user->id, 'type' => 'weight_goal_apply']);
    }

    public function test_goal_suggestion_appears_after_two_kg_change_with_full_profile(): void
    {
        $user = User::factory()->create([
            'birth_year'   => 1995,
            'gender'       => 'male',
            'height_cm'    => 175,
            'weight_kg'    => 90,
            'calorie_goal' => 2000,
        ]);
        $user->weightLogs()->create(['weight_kg' => 90, 'logged_date' => today()->subDays(20)]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/weight/log', [
            'weight_kg' => 87,
        ]);

        $response->assertStatus(201);
        $this->assertNotNull($response->json('goal_suggestion'));
    }

    public function test_goal_suggestion_is_null_when_change_is_small(): void
    {
        $user = User::factory()->create([
            'birth_year'   => 1995,
            'gender'       => 'male',
            'height_cm'    => 175,
            'weight_kg'    => 90,
            'calorie_goal' => 2000,
        ]);
        $user->weightLogs()->create(['weight_kg' => 90, 'logged_date' => today()->subDays(20)]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/weight/log', [
            'weight_kg' => 89.5,
        ]);

        $this->assertNull($response->json('goal_suggestion'));
    }
}
