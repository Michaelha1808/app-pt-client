<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HealthActivity;
use App\Models\MealPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyTaskController extends Controller
{
    /**
     * Nhiệm vụ tập luyện HÔM NAY. Ưu tiên lấy đúng ngày trong kế hoạch TUẦN (đổi theo
     * từng ngày); nếu chưa có kế hoạch tuần thì fallback về workout đầu của kế hoạch daily.
     * Đánh dấu hoàn thành nếu hôm nay đã có buổi tập (manual hoặc Strava).
     *
     * Bữa ăn & nước do client tự lấy (useMealLog / useWater) — endpoint này chỉ bổ
     * sung phần cá nhân hóa theo kế hoạch.
     */
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $today = now(config('app.timezone'));

        [$w, $hasPlan] = $this->workoutFromWeekly($user->id, $today);

        // Fallback: kế hoạch daily cũ (workout đầu tiên).
        if (!$w) {
            $daily    = MealPlan::where('user_id', $user->id)
                ->where('scope', 'daily')
                ->latest('target_date')
                ->first();
            $hasPlan  = $hasPlan || (bool) $daily;
            $w        = $daily?->plan['workouts'][0] ?? null;
        }

        $workout = null;
        if ($w) {
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
            'has_plan' => $hasPlan,
            'workout'  => $workout,
        ]);
    }

    /**
     * Lấy workout của đúng thứ hôm nay từ kế hoạch tuần hiện tại.
     *
     * @return array{0: ?array<string,mixed>, 1: bool}  [workout, có kế hoạch tuần?]
     */
    private function workoutFromWeekly(int $userId, \Illuminate\Support\Carbon $today): array
    {
        $weekly = MealPlan::where('user_id', $userId)
            ->where('scope', 'weekly')
            ->where('target_date', $today->copy()->startOfWeek()->toDateString())
            ->first();

        if (!$weekly) {
            return [null, false];
        }

        $weekday = $today->dayOfWeekIso; // 1 = Thứ 2 … 7 = Chủ nhật
        foreach ($weekly->plan['days'] ?? [] as $day) {
            if ((int) ($day['weekday'] ?? 0) === $weekday) {
                return [$day['workout'] ?? null, true];
            }
        }

        return [null, true];
    }
}
