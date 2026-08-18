<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PlanProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tiến độ thực hiện kế hoạch: % hoàn thành hôm nay + cả tuần, kèm lời động viên.
 * Tiến độ suy ra từ log ăn/tập thực tế (xem PlanProgressService), không cần user tự tick.
 */
class PlanProgressController extends Controller
{
    public function __construct(private PlanProgressService $progress)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $today = now(config('app.timezone'));

        $day  = $this->progress->dayProgress($user->id, $today);
        $week = $this->progress->weekProgress($user->id, $today);

        return response()->json([
            'today'         => $day,
            'week'          => $week,
            'encouragement' => $this->progress->encouragement($day['percent'], $week['percent'], $day['has_plan']),
        ]);
    }
}
