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
    public function log(Request $request, WeightService $service): JsonResponse
    {
        $data = $request->validate([
            'weight_kg'   => 'required|numeric|between:20,500',
            'logged_date' => 'sometimes|date|before_or_equal:today',
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

    public function history(Request $request, WeightService $service): JsonResponse
    {
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

    public function applyGoal(Request $request, WeightService $service): JsonResponse
    {
        $data = $request->validate([
            'calorie_goal' => 'required|integer|between:1000,5000',
        ]);

        $service->applyGoal($request->user(), (int) $data['calorie_goal']);

        return response()->json([
            'message'      => 'Đã cập nhật mục tiêu calo',
            'calorie_goal' => (int) $data['calorie_goal'],
        ]);
    }
}
