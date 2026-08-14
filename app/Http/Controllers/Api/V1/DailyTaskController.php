<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HealthActivity;
use App\Models\MealPlan;
use App\Services\Health\HealthActivityWriter;
use App\Services\StreakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class DailyTaskController extends Controller
{
    /** Nhãn thứ khớp schema AI dùng trong weeklyPrompt()/monthlyPrompt() (MealPlanService). */
    private const WEEKDAY_LABELS = [
        1 => 'Thứ 2', 2 => 'Thứ 3', 3 => 'Thứ 4', 4 => 'Thứ 5',
        5 => 'Thứ 6', 6 => 'Thứ 7', 7 => 'Chủ nhật',
    ];

    /** Map loại bài tập trong kế hoạch AI (cardio/strength/flexibility) sang key MET (config('health.met')). */
    private const WORKOUT_TYPE_TO_MET = [
        'cardio'      => 'run',
        'strength'    => 'workout',
        'flexibility' => 'yoga',
    ];

    private const MEAL_SLOTS = ['breakfast', 'lunch', 'dinner', 'snack'];

    /**
     * Nhiệm vụ ăn uống & tập luyện HÔM NAY. Ưu tiên kế hoạch daily riêng cho hôm nay
     * (từ chat "Thiết lập kế hoạch ăn hôm nay" hoặc /plan), rồi tới đúng ngày trong
     * kế hoạch TUẦN, rồi fallback: workout đầu của plan daily mới nhất, hoặc buổi tập
     * theo thứ trong kế hoạch THÁNG (weekly_workout_split) nếu không có gì khác.
     *
     * Bữa ăn & nước do client tự lấy (useMealLog / useWater) — endpoint này chỉ bổ
     * sung phần cá nhân hóa theo kế hoạch.
     */
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $today = now(config('app.timezone'));

        $tasks = $this->resolveTodayTasks($user->id, $today);

        $workout = null;
        if ($w = $tasks['workout']) {
            $doneToday = HealthActivity::where('user_id', $user->id)
                ->whereDate('started_at', $today->toDateString())
                ->exists();

            $workout = [
                'name'         => $w['name'] ?? 'Buổi tập hôm nay',
                'type'         => $w['type'] ?? null,
                'duration_min' => isset($w['duration_min']) ? (int) $w['duration_min'] : null,
                'done'         => $doneToday,
            ];
        }

        return response()->json([
            'has_plan' => $tasks['has_plan'],
            'workout'  => $workout,
            'meals'    => $this->mealTasks($user->id, $tasks['meals'], $today),
        ]);
    }

    /**
     * "Thực hiện" nhiệm vụ bữa ăn: ghi luôn 1 MealLog đúng như bữa trong kế hoạch hôm nay —
     * không cần chờ nhận diện theo khung giờ như trước. Dùng khi user ăn đúng gợi ý của AI.
     */
    public function completeMeal(Request $request, StreakService $streakService): JsonResponse
    {
        $data = $request->validate([
            'slot' => ['required', 'string', Rule::in(self::MEAL_SLOTS)],
        ]);

        $user  = $request->user();
        $today = now(config('app.timezone'));

        $meals = $this->resolveTodayTasks($user->id, $today)['meals'] ?? [];
        $meal  = collect($meals)->firstWhere('slot', $data['slot']);

        abort_if(!$meal, 422, 'Không tìm thấy bữa ăn này trong kế hoạch hôm nay.');

        $log = $user->mealLogs()->create([
            'food_name' => $meal['name'] ?? ($meal['items'][0] ?? 'Bữa ăn theo kế hoạch'),
            'serving'   => !empty($meal['items']) ? implode(', ', $meal['items']) : null,
            'calories'  => (int) ($meal['calories'] ?? 0),
            'protein'   => (int) ($meal['protein'] ?? 0),
            'carbs'     => (int) ($meal['carbs'] ?? 0),
            'fat'       => (int) ($meal['fat'] ?? 0),
            'sodium'    => 0,
            'logged_at' => $today,
        ]);

        $streak = $streakService->recordMealActivity($user->load('streakMilestones', 'notificationSubscriptions'));

        return response()->json([
            'message' => 'Đã ghi lại bữa ăn theo kế hoạch',
            'id'      => $log->id,
            'streak'  => $streak,
        ], 201);
    }

    /**
     * "Thực hiện" nhiệm vụ tập luyện: ghi luôn 1 HealthActivity thủ công đúng như buổi tập
     * trong kế hoạch hôm nay (daily/weekly/monthly) — không cần chờ Strava/log tay riêng.
     */
    public function completeWorkout(Request $request, StreakService $streakService): JsonResponse
    {
        $user  = $request->user();
        $today = now(config('app.timezone'));

        $workout = $this->resolveTodayTasks($user->id, $today)['workout'];
        abort_if(!$workout, 422, 'Không tìm thấy buổi tập nào trong kế hoạch hôm nay.');

        $type            = self::WORKOUT_TYPE_TO_MET[$workout['type'] ?? ''] ?? 'other';
        $durationMinutes = (int) ($workout['duration_min'] ?? 30);
        $durationSeconds = max(60, $durationMinutes * 60);
        $calories        = isset($workout['est_calories_burned'])
            ? (int) $workout['est_calories_burned']
            : HealthActivityWriter::estimateCalories($type, $durationSeconds, $user->weight_kg);

        $activity = $user->healthActivities()->create([
            'provider'         => 'manual',
            'source'           => 'manual',
            'type'             => $type,
            'name'             => $workout['name'] ?? 'Buổi tập theo kế hoạch',
            'started_at'       => $today,
            'duration_seconds' => $durationSeconds,
            'calories'         => $calories,
        ]);

        $streak = $streakService->recordActivity($user->load('streakMilestones', 'notificationSubscriptions'));

        return response()->json([
            'message' => 'Đã ghi lại buổi tập theo kế hoạch',
            'id'      => $activity->id,
            'streak'  => $streak,
        ], 201);
    }

    /**
     * Bữa ăn & buổi tập của HÔM NAY từ bất kỳ kế hoạch nào đang active — dùng chung cho
     * index() (hiển thị) và completeMeal()/completeWorkout() (thực hiện).
     *
     * @return array{has_plan: bool, meals: ?array<int, array<string, mixed>>, workout: ?array<string, mixed>}
     */
    private function resolveTodayTasks(int $userId, Carbon $today): array
    {
        // Kế hoạch daily đã thiết lập RIÊNG cho hôm nay (từ chat "Thiết lập kế hoạch ăn hôm nay").
        $todayPlan = MealPlan::where('user_id', $userId)
            ->where('scope', 'daily')
            ->whereDate('target_date', $today->toDateString())
            ->first();

        $weeklyToday = $this->weeklyDayForToday($userId, $today);

        $meals       = $todayPlan?->plan['meals'] ?? $weeklyToday['day']['meals'] ?? null;
        $mealsExists = (bool) $todayPlan || $weeklyToday['exists'];

        $workout = $weeklyToday['day']['workout'] ?? null;
        $hasPlan = $weeklyToday['exists'];

        // Fallback workout: plan hôm nay → plan daily mới nhất.
        if (!$workout) {
            $daily   = $todayPlan ?? MealPlan::where('user_id', $userId)
                ->where('scope', 'daily')
                ->latest('target_date')
                ->first();
            $hasPlan = $hasPlan || (bool) $daily;
            $workout = $daily?->plan['workouts'][0] ?? null;
        }

        // Fallback thứ 3: không daily, không weekly → buổi tập theo thứ trong kế hoạch THÁNG.
        if (!$workout) {
            $monthly = $this->workoutFromMonthly($userId, $today);
            $hasPlan = $hasPlan || $monthly['exists'];
            $workout = $monthly['workout'];
        }

        return [
            'has_plan' => $mealsExists || $hasPlan,
            'meals'    => $meals,
            'workout'  => $workout,
        ];
    }

    /**
     * Các bữa trong kế hoạch hôm nay (daily riêng hoặc đúng ngày trong kế hoạch tuần) →
     * nhiệm vụ. done = đã có bữa ăn ghi nhận trong khung giờ của slot (hoặc "thực hiện" thủ công).
     *
     * @param  ?array<int,array<string,mixed>>  $meals
     * @return array<int,array{slot:string,name:string,calories:?int,done:bool}>
     */
    private function mealTasks(int $userId, ?array $meals, Carbon $today): array
    {
        if (empty($meals)) {
            return [];
        }

        // Giờ của các bữa đã log hôm nay → suy ra slot nào đã ăn.
        $loggedHours = \App\Models\MealLog::where('user_id', $userId)
            ->whereDate('logged_at', $today->toDateString())
            ->pluck('logged_at')
            ->map(fn ($t) => $t->hour);

        $slotDone = fn (string $slot): bool => $loggedHours->contains(
            fn (int $h) => match ($slot) {
                'breakfast' => $h >= 4 && $h < 11,
                'lunch'     => $h >= 11 && $h < 15,
                'dinner'    => $h >= 17 && $h < 23,
                'snack'     => ($h >= 15 && $h < 17) || $h >= 21 || $h < 4,
                default     => false,
            }
        );

        return collect($meals)
            ->map(fn ($m) => [
                'slot'     => $m['slot'] ?? 'snack',
                'name'     => $m['name'] ?? ($m['items'][0] ?? 'Bữa ăn'),
                'calories' => isset($m['calories']) ? (int) $m['calories'] : null,
                'done'     => $slotDone($m['slot'] ?? 'snack'),
            ])
            ->values()
            ->all();
    }

    /**
     * Ngày (trong 7 ngày kế hoạch tuần hiện tại) khớp hôm nay, nếu có kế hoạch tuần đang active.
     * Dùng chung cho cả workout lẫn meals — tránh lặp query MealPlan scope=weekly.
     *
     * @return array{exists: bool, day: ?array<string, mixed>}
     */
    private function weeklyDayForToday(int $userId, Carbon $today): array
    {
        $weekly = MealPlan::where('user_id', $userId)
            ->where('scope', 'weekly')
            ->where('target_date', $today->copy()->startOfWeek()->toDateString())
            ->first();

        if (!$weekly) {
            return ['exists' => false, 'day' => null];
        }

        $weekday = $today->dayOfWeekIso; // 1 = Thứ 2 … 7 = Chủ nhật
        foreach ($weekly->plan['days'] ?? [] as $day) {
            if ((int) ($day['weekday'] ?? 0) === $weekday) {
                return ['exists' => true, 'day' => $day];
            }
        }

        return ['exists' => true, 'day' => null];
    }

    /**
     * Buổi tập theo thứ hôm nay từ kế hoạch THÁNG hiện tại (weekly_workout_split) — chỉ dùng
     * làm fallback cuối khi không có kế hoạch daily/weekly nào. Monthly không có dữ liệu bữa
     * ăn theo ngày nên không áp dụng cho meals.
     *
     * @return array{exists: bool, workout: ?array<string, mixed>}
     */
    private function workoutFromMonthly(int $userId, Carbon $today): array
    {
        $monthly = MealPlan::where('user_id', $userId)
            ->where('scope', 'monthly')
            ->where('target_date', $today->copy()->startOfMonth()->toDateString())
            ->first();

        if (!$monthly) {
            return ['exists' => false, 'workout' => null];
        }

        $todayLabel = self::WEEKDAY_LABELS[$today->dayOfWeekIso] ?? null;

        foreach ($monthly->plan['weekly_workout_split'] ?? [] as $item) {
            if ($todayLabel !== null && trim((string) ($item['day'] ?? '')) === $todayLabel) {
                return [
                    'exists'  => true,
                    'workout' => [
                        'name'         => $item['activity'] ?? 'Buổi tập hôm nay',
                        'type'         => null, // schema monthly không có "type" (cardio/strength/...)
                        'duration_min' => $item['duration_min'] ?? null,
                    ],
                ];
            }
        }

        return ['exists' => true, 'workout' => null];
    }
}
