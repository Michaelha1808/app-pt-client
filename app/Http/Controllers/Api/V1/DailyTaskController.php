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
     * Nhiệm vụ tập luyện hôm nay, lấy từ kế hoạch daily mới nhất mà AI đã phân tích
     * cho user. Đánh dấu hoàn thành nếu hôm nay đã có buổi tập (manual hoặc Strava).
     *
     * Bữa ăn & nước do client tự lấy (useMealLog / useWater) — endpoint này chỉ bổ
     * sung phần cá nhân hóa theo kế hoạch.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $plan = MealPlan::where('user_id', $user->id)
            ->where('scope', 'daily')
            ->latest('target_date')
            ->first();

        $workout  = null;
        $workouts = $plan?->plan['workouts'] ?? [];

        if (!empty($workouts)) {
            $w = $workouts[0];

            $doneToday = HealthActivity::where('user_id', $user->id)
                ->whereDate('started_at', now(config('app.timezone'))->toDateString())
                ->exists();

            $workout = [
                'name'         => $w['name'] ?? 'Buổi tập hôm nay',
                'type'         => $w['type'] ?? null,
                'duration_min' => isset($w['duration_min']) ? (int) $w['duration_min'] : null,
                'done'         => $doneToday,
            ];
        }

        return response()->json([
            'has_plan' => (bool) $plan,
            'workout'  => $workout,
        ]);
    }
}
