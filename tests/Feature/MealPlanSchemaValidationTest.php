<?php

namespace Tests\Feature;

use App\Services\MealPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Test riêng cho MealPlanService::validatePlanSchema() (private) qua reflection — tránh gọi
 * Gemini thật (getStructuredPlan()/planFromConversation() luôn gọi API) mà vẫn phủ được đúng
 * logic quan trọng: field thiếu/sai kiểu từ AI phải bị chặn trước khi lưu vào meal_plans.plan.
 */
class MealPlanSchemaValidationTest extends TestCase
{
    use RefreshDatabase;

    private function validate(array $plan, string $scope): array
    {
        $service = app(MealPlanService::class);
        $method  = new ReflectionMethod(MealPlanService::class, 'validatePlanSchema');
        $method->setAccessible(true);

        return $method->invoke($service, $plan, $scope, json_encode($plan), 'STOP');
    }

    private function validDailyPlan(): array
    {
        return [
            'summary'          => 'Kế hoạch cân bằng',
            'target_calories'  => 1800,
            'target_macros'    => ['protein' => 120, 'carbs' => 180, 'fat' => 60],
            'water_target_ml'  => 2000,
            'meals'            => [
                ['slot' => 'breakfast', 'name' => 'Phở gà', 'items' => ['Phở', 'Gà'], 'calories' => 400],
            ],
            'workouts'         => [
                ['name' => 'Đi bộ', 'type' => 'cardio', 'duration_min' => 30, 'intensity' => 'medium', 'est_calories_burned' => 150],
            ],
            'tips'             => ['Uống đủ nước'],
        ];
    }

    public function test_valid_daily_plan_passes(): void
    {
        $plan = $this->validate($this->validDailyPlan(), 'daily');
        $this->assertSame(1800.0, (float) $plan['target_calories']);
    }

    public function test_daily_plan_missing_target_calories_is_rejected(): void
    {
        $plan = $this->validDailyPlan();
        unset($plan['target_calories']);

        $this->expectException(\RuntimeException::class);
        $this->validate($plan, 'daily');
    }

    public function test_daily_plan_with_invalid_meal_slot_is_rejected(): void
    {
        $plan = $this->validDailyPlan();
        $plan['meals'][0]['slot'] = 'midnight-feast'; // không nằm trong breakfast/lunch/dinner/snack

        $this->expectException(\RuntimeException::class);
        $this->validate($plan, 'daily');
    }

    public function test_daily_plan_with_non_numeric_calories_is_rejected(): void
    {
        $plan = $this->validDailyPlan();
        $plan['meals'][0]['calories'] = 'nhiều'; // Gemini thỉnh thoảng trả chữ thay vì số

        $this->expectException(\RuntimeException::class);
        $this->validate($plan, 'daily');
    }

    public function test_daily_plan_with_empty_workouts_array_is_allowed(): void
    {
        // workouts rỗng là hợp lệ (không phải ngày nào cũng có buổi tập gợi ý)
        $plan = $this->validDailyPlan();
        $plan['workouts'] = [];

        $plan = $this->validate($plan, 'daily');
        $this->assertSame([], $plan['workouts']);
    }

    public function test_daily_plan_with_malformed_workout_item_is_rejected(): void
    {
        $plan = $this->validDailyPlan();
        $plan['workouts'][0] = ['name' => 'Tập gì đó']; // thiếu type/duration_min

        $this->expectException(\RuntimeException::class);
        $this->validate($plan, 'daily');
    }

    public function test_valid_weekly_plan_passes(): void
    {
        $days = collect(range(1, 7))->map(fn ($d) => [
            'weekday'         => $d,
            'label'           => "Ngày $d",
            'target_calories' => 1800,
            'meals'           => [
                ['slot' => 'lunch', 'name' => 'Cơm gà', 'calories' => 600],
            ],
        ])->all();

        $plan = $this->validate([
            'summary' => 'Kế hoạch tuần', 'days' => $days, 'tips' => [],
        ], 'weekly');

        $this->assertCount(7, $plan['days']);
    }

    public function test_weekly_plan_with_fewer_than_7_days_is_rejected(): void
    {
        $days = collect(range(1, 5))->map(fn ($d) => [
            'weekday' => $d, 'label' => "Ngày $d", 'target_calories' => 1800,
            'meals'   => [['slot' => 'lunch', 'name' => 'Cơm gà', 'calories' => 600]],
        ])->all();

        $this->expectException(\RuntimeException::class);
        $this->validate(['summary' => 'x', 'days' => $days, 'tips' => []], 'weekly');
    }

    public function test_valid_monthly_plan_passes(): void
    {
        $plan = $this->validate([
            'summary'                 => 'Kế hoạch tháng',
            'avg_daily_calories'      => 1800,
            'target_macros'           => ['protein' => 120, 'carbs' => 180, 'fat' => 60],
            'weekly_focus'            => [],
            'weekly_workout_split'    => [],
            'tips'                    => [],
        ], 'monthly');

        $this->assertSame(1800.0, (float) $plan['avg_daily_calories']);
    }

    public function test_monthly_plan_missing_avg_daily_calories_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validate([
            'summary' => 'x', 'target_macros' => ['protein' => 1, 'carbs' => 1, 'fat' => 1],
            'weekly_focus' => [], 'weekly_workout_split' => [], 'tips' => [],
        ], 'monthly');
    }

    public function test_empty_plan_is_rejected_for_every_scope(): void
    {
        foreach (['daily', 'weekly', 'monthly'] as $scope) {
            try {
                $this->validate([], $scope);
                $this->fail("scope={$scope}: mảng rỗng phải bị từ chối");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('không đúng định dạng', $e->getMessage());
            }
        }
    }
}
