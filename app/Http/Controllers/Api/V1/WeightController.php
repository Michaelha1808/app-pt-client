<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WeightLog;
use App\Services\WeightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class WeightController extends Controller
{
    // DEFENSE: endpoint log cân nặng — upsert theo logged_date, tự sync users.weight_kg
    public function log(Request $request, WeightService $service): JsonResponse
    {
        $data = $request->validate([
            // DEFENSE: cân nặng weight log — cùng khoảng 20-500 kg với register
            'weight_kg'   => 'required|numeric|between:20,500',
            'logged_date' => 'sometimes|date|before_or_equal:today',
            // DEFENSE: giới hạn ghi chú cân nặng — max 200 ký tự
            'note'        => 'nullable|string|max:200',
        ]);

        $user  = $request->user();
        $date  = isset($data['logged_date']) ? Carbon::parse($data['logged_date']) : null;
        $wasNew = !$user->weightLogs()->whereDate('logged_date', $date ?? today())->exists();

        $entry = $service->logWeight($user, (float) $data['weight_kg'], $date, $data['note'] ?? null);
        $user->refresh();

        return response()->json([
            'entry' => [
                'id'          => $entry->id,
                'weight_kg'   => (float) $entry->weight_kg,
                'logged_date' => $entry->logged_date->toDateString(),
                'note'        => $entry->note,
            ],
            'current_weight_kg' => (float) $user->weight_kg,
            'goal_suggestion'   => $service->suggestGoal($user),
        ], $wasNew ? 201 : 200);
    }

    // DEFENSE: endpoint lịch sử cân nặng — trả history + trend + chart data
    public function history(Request $request, WeightService $service): JsonResponse
    {
        // DEFENSE: khoảng ngày mặc định weight history — 30 ngày, đổi query ?range=7/60/90
        $range = (int) $request->query('range', 30);

        return response()->json($service->history($request->user(), $range));
    }

    public function destroy(Request $request, WeightLog $weightLog, WeightService $service): JsonResponse
    {
        abort_if($weightLog->user_id !== $request->user()->id, 403);

        $service->deleteEntry($weightLog);

        return response()->json([
            'current_weight_kg' => (float) $request->user()->fresh()->weight_kg,
        ]);
    }

    // DEFENSE: endpoint áp dụng gợi ý calo mới — user đồng ý thay users.calorie_goal
    public function applyGoal(Request $request, WeightService $service): JsonResponse
    {
        $data = $request->validate([
            // DEFENSE: khoảng calo apply-goal — 1000-5000 kcal/ngày (đồng bộ register)
            'calorie_goal' => 'required|integer|between:1000,5000',
        ]);

        $service->applyGoal($request->user(), (int) $data['calorie_goal']);

        // DEFENSE: text apply-goal thành công
        return response()->json([
            'message'      => 'Đã cập nhật mục tiêu calo',
            'calorie_goal' => (int) $data['calorie_goal'],
        ]);
    }
}
