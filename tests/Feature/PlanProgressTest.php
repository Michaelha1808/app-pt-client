<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanProgressTest extends TestCase
{
    use RefreshDatabase;

    /** Kế hoạch daily cho hôm nay: 2 bữa (sáng, trưa) + 1 buổi tập → tổng 3 nhiệm vụ. */
    private function planForToday(User $user): void
    {
        $user->mealPlans()->create([
            'scope'       => 'daily',
            'target_date' => today()->toDateString(),
            'plan'        => [
                'meals' => [
                    ['slot' => 'breakfast', 'name' => 'Phở gà', 'calories' => 400],
                    ['slot' => 'lunch', 'name' => 'Cơm gà', 'calories' => 600],
                ],
                'workouts' => [
                    ['name' => 'Đi bộ nhanh', 'type' => 'cardio', 'duration_min' => 30],
                ],
            ],
            'context_snapshot' => ['calorie_goal' => 1800],
            'data_hash'        => 'test',
        ]);
    }

    public function test_progress_is_zero_when_nothing_logged(): void
    {
        $user = User::factory()->create();
        $this->planForToday($user);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/plan/progress');

        $response->assertStatus(200)
            ->assertJsonPath('today.total', 3)
            ->assertJsonPath('today.done', 0)
            ->assertJsonPath('today.percent', 0)
            ->assertJsonPath('today.has_plan', true);
    }

    public function test_logging_a_meal_in_slot_window_counts_toward_progress(): void
    {
        $user = User::factory()->create();
        $this->planForToday($user);

        // 8h sáng → rơi vào khung breakfast (4h-11h)
        $user->mealLogs()->create([
            'food_name' => 'Phở gà', 'calories' => 400, 'protein' => 30, 'carbs' => 40, 'fat' => 10,
            'sodium' => 0, 'logged_at' => today()->setTime(8, 0),
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/plan/progress');

        $response->assertStatus(200)
            ->assertJsonPath('today.done', 1)
            ->assertJsonPath('today.total', 3)
            ->assertJsonPath('today.percent', 33);
    }

    public function test_completing_everything_gives_100_percent_and_praise(): void
    {
        $user = User::factory()->create();
        $this->planForToday($user);

        $user->mealLogs()->create([
            'food_name' => 'Phở gà', 'calories' => 400, 'protein' => 30, 'carbs' => 40, 'fat' => 10,
            'sodium' => 0, 'logged_at' => today()->setTime(8, 0),
        ]);
        $user->mealLogs()->create([
            'food_name' => 'Cơm gà', 'calories' => 600, 'protein' => 45, 'carbs' => 60, 'fat' => 18,
            'sodium' => 0, 'logged_at' => today()->setTime(12, 0),
        ]);
        $user->healthActivities()->create([
            'provider' => 'manual', 'source' => 'manual', 'type' => 'run', 'name' => 'Đi bộ nhanh',
            'started_at' => today()->setTime(18, 0), 'duration_seconds' => 1800, 'calories' => 200,
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/plan/progress');

        $response->assertStatus(200)
            ->assertJsonPath('today.percent', 100)
            ->assertJsonPath('encouragement.emoji', '🏆');
    }

    public function test_meal_logged_by_name_counts_even_outside_its_time_window(): void
    {
        // Bấm "Thực hiện" bữa sáng vào buổi tối → log rơi vào khung giờ bữa tối. Nếu chỉ xét
        // giờ thì đánh dấu nhầm bữa tối xong; khớp theo tên món để đúng bữa người dùng đã bấm.
        $user = User::factory()->create();
        $this->planForToday($user);

        $user->mealLogs()->create([
            'food_name' => 'Phở gà', 'calories' => 400, 'protein' => 30, 'carbs' => 40, 'fat' => 10,
            'sodium' => 0, 'logged_at' => today()->setTime(20, 30),
        ]);

        $meals = $this->actingAs($user, 'sanctum')->getJson('/api/v1/plan/progress')->json('today.meals');

        $breakfast = collect($meals)->firstWhere('slot', 'breakfast');
        $lunch     = collect($meals)->firstWhere('slot', 'lunch');

        $this->assertTrue($breakfast['done'], 'Bữa sáng đã bấm thực hiện phải được tính là xong');
        $this->assertFalse($lunch['done'], 'Bữa trưa chưa ăn thì không được tính');

        // Log đã khớp tên không được tính thêm lần nữa theo khung giờ (20h30 = khung bữa tối)
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/plan/progress')
            ->assertJsonPath('today.done', 1);
    }

    public function test_no_plan_returns_zero_and_starter_encouragement(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/plan/progress');

        $response->assertStatus(200)
            ->assertJsonPath('today.has_plan', false)
            ->assertJsonPath('today.total', 0)
            ->assertJsonPath('today.percent', 0)
            ->assertJsonPath('encouragement.emoji', '🌱');
    }

    public function test_week_has_seven_days_and_future_days_are_not_counted(): void
    {
        $user = User::factory()->create();
        $this->planForToday($user);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/plan/progress');

        $response->assertStatus(200)->assertJsonCount(7, 'week.days');

        $days = $response->json('week.days');
        foreach ($days as $day) {
            if ($day['is_future']) {
                // Ngày chưa tới không có % (không kéo tụt tiến độ tuần)
                $this->assertNull($day['percent']);
            } else {
                $this->assertIsInt($day['percent']);
            }
        }

        // Đúng 1 ngày được đánh dấu hôm nay
        $this->assertCount(1, array_filter($days, fn ($d) => $d['is_today']));
    }

    public function test_progress_requires_authentication(): void
    {
        $this->getJson('/api/v1/plan/progress')->assertStatus(401);
    }
}
