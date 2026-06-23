<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WaterLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaterController extends Controller
{
    public function today(Request $request): JsonResponse
    {
        $logs = $request->user()
            ->waterLogs()
            ->whereDate('logged_at', today())
            ->orderBy('logged_at')
            ->get(['id', 'amount_ml', 'logged_at']);

        return response()->json([
            'total_ml' => $logs->sum('amount_ml'),
            'goal_ml'  => 2000,
            'logs'     => $logs->map(fn ($l) => [
                'id'        => $l->id,
                'amount_ml' => $l->amount_ml,
                'logged_at' => $l->logged_at->format('H:i'),
            ]),
        ]);
    }

    public function log(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount_ml' => 'required|integer|min:50|max:2000',
        ]);

        $log = $request->user()->waterLogs()->create([
            'amount_ml' => $data['amount_ml'],
            'logged_at' => now(),
        ]);

        $total = $request->user()
            ->waterLogs()
            ->whereDate('logged_at', today())
            ->sum('amount_ml');

        return response()->json([
            'id'       => $log->id,
            'total_ml' => (int) $total,
            'goal_ml'  => 2000,
            'reached'  => $total >= 2000,
        ], 201);
    }

    public function delete(Request $request, WaterLog $waterLog): JsonResponse
    {
        abort_if($waterLog->user_id !== $request->user()->id, 403);
        $waterLog->delete();

        $total = $request->user()
            ->waterLogs()
            ->whereDate('logged_at', today())
            ->sum('amount_ml');

        return response()->json(['total_ml' => (int) $total]);
    }
}
