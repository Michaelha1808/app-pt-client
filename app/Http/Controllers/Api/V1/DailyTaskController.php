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

        // Kế hoạch daily đã thiết lập RIÊNG cho hôm nay (từ chat "Thiết lập kế hoạch ăn hôm nay").
        $todayPlan = MealPlan::where('user_id', $user->id)
            ->where('scope', 'daily')
            ->whereDate('target_date', $today->toDateString())
            ->first();

        [$w, $hasPlan] = $this->workoutFromWeekly($user->id, $today);

        // Fallback workout: plan hôm nay → plan daily mới nhất.
        if (!$w) {
            $daily   = $todayPlan ?? MealPlan::where('user_id', $user->id)
                ->where('scope', 'daily')
                ->latest('target_date')
                ->first();
            $hasPlan = $hasPlan || (bool) $daily;
            $w       = $daily?->plan['workouts'][0] ?? null;
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
            'meals'    => $this->mealTasks($user->id, $todayPlan, $today),
        ]);
    }

    /**
     * Các bữa trong kế hoạch hôm nay → nhiệm vụ. done = đã có bữa ăn ghi nhận trong khung giờ của slot.
     *
     * @return array<int,array{slot:string,name:string,calories:?int,done:bool}>
     */
    private function mealTasks(int $userId, ?MealPlan $todayPlan, \Illuminate\Support\Carbon $today): array
    {
        if (!$todayPlan || empty($todayPlan->plan['meals'])) {
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

        return collect($todayPlan->plan['meals'])
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
