<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\StreakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StreakController extends Controller
{
    public function __construct(private StreakService $streakService) {}

    public function show(Request $request): JsonResponse
    {
        $data = $this->streakService->getStreakData($request->user()->load('streakMilestones'));

        return response()->json($data);
    }

    public function useFreeze(Request $request): JsonResponse
    {
        try {
            $streak = $this->streakService->useFreeze($request->user());

            return response()->json([
                'message'               => 'Đã dùng Freeze Token để bảo vệ chuỗi! ❄️',
                'freeze_tokens'         => $streak->freeze_tokens,
                'freeze_last_used_date' => $streak->freeze_last_used_date?->toDateString(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
