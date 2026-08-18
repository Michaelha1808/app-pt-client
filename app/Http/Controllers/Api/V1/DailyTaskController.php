<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Health\HealthActivityWriter;
use App\Services\PlanProgressService;
use App\Services\StreakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DailyTaskController extends Controller
{
    /** Map loại bài tập trong kế hoạch AI (cardio/strength/flexibility) sang key MET (config('health.met')). */
    private const WORKOUT_TYPE_TO_MET = [
        'cardio'      => 'run',
        'strength'    => 'workout',
        'flexibility' => 'yoga',
    ];

    private const MEAL_SLOTS = ['breakfast', 'lunch', 'dinner', 'snack'];

    public function __construct(private PlanProgressService $progress)
    {
    }

    /**
     * Nhiệm vụ ăn uống & tập luyện HÔM NAY. Việc phân giải kế hoạch nào áp dụng cho hôm nay
     * (daily riêng → đúng ngày trong kế hoạch tuần → fallback tháng) nằm ở PlanProgressService,
     * dùng chung với trang Tiến độ để hai nơi không suy luận "đã xong" khác nhau.
     *
     * Bữa ăn & nước do client tự lấy (useMealLog / useWater) — endpoint này chỉ bổ
     * sung phần cá nhân hóa theo kế hoạch.
     */
    public function index(Request $request): JsonResponse
    {
        $day = $this->progress->dayProgress($request->user()->id, now(config('app.timezone')));

        return response()->json([
            'has_plan' => $day['has_plan'],
            'workout'  => $day['workout'],
            'meals'    => $day['meals'],
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

        $meals = $this->progress->resolveTasks($user->id, $today)['meals'] ?? [];
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

        $workout = $this->progress->resolveTasks($user->id, $today)['workout'];
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
}
