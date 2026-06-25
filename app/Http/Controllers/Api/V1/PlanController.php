<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MealPlan;
use App\Services\MealPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlanController extends Controller
{
    /** Lấy kế hoạch hiện hành + cờ stale. */
    public function show(Request $request, MealPlanService $service): JsonResponse
    {
        $scope      = $request->query('scope', 'daily') === 'monthly' ? 'monthly' : 'daily';
        $targetDate = $this->targetDate($scope);
        $user       = $request->user();

        $plan = $user->mealPlans()
            ->where('scope', $scope)
            ->where('target_date', $targetDate)
            ->first();

        if (!$plan) {
            return response()->json(['plan' => null, 'needs_generation' => true, 'reason' => 'missing']);
        }

        // So data_hash hiện tại để biết stale
        $isStale = false;
        try {
            $ctx     = $service->buildContext($user, $scope);
            $isStale = ($ctx['data_hash'] ?? null) !== $plan->data_hash;
        } catch (\Throwable $e) {
            // thiếu hồ sơ → bỏ qua check stale
        }

        return response()->json([
            'plan'         => $plan->plan,
            'reasoning'    => $plan->reasoning,
            'target_date'  => $plan->target_date->toDateString(),
            'is_stale'     => $isStale,
            'generated_at' => $plan->updated_at?->toIso8601String(),
        ]);
    }

    /** Sinh kế hoạch mới — SSE 2-phase, upsert vào DB. */
    public function generate(Request $request, MealPlanService $service): StreamedResponse
    {
        $request->validate(['scope' => 'nullable|in:daily,monthly']);
        $scope      = $request->input('scope', 'daily');
        $targetDate = $this->targetDate($scope);
        $user       = $request->user();

        try {
            $context = $service->buildContext($user, $scope);
        } catch (\Throwable $e) {
            return response()->stream(function () use ($e) {
                echo 'data: ' . json_encode(['type' => 'error', 'message' => $e->getMessage()]) . "\n\n";
                echo "data: [DONE]\n\n";
            }, 200, $this->sseHeaders());
        }

        return response()->stream(
            function () use ($service, $user, $scope, $targetDate, $context) {
                while (ob_get_level()) {
                    ob_end_clean();
                }
                try {
                    $plan = $service->getStructuredPlan($context, $scope);
                    echo 'data: ' . json_encode(['type' => 'plan', 'data' => $plan]) . "\n\n";
                    flush();

                    $record = $user->mealPlans()->updateOrCreate(
                        ['scope' => $scope, 'target_date' => $targetDate],
                        [
                            'plan'             => $plan,
                            'context_snapshot' => $context,
                            'data_hash'        => $context['data_hash'],
                            'reasoning'        => null,
                        ]
                    );

                    $reasoning = '';
                    foreach ($service->streamReasoning($context, $plan, $scope) as $delta) {
                        $reasoning .= $delta;
                        echo 'data: ' . json_encode(['type' => 'text', 'delta' => $delta]) . "\n\n";
                        flush();
                    }
                    $record->update(['reasoning' => $reasoning]);
                } catch (\Throwable $e) {
                    echo 'data: ' . json_encode(['type' => 'error', 'message' => 'Không thể tạo kế hoạch. Vui lòng thử lại.']) . "\n\n";
                    flush();
                }
                echo "data: [DONE]\n\n";
                flush();
            },
            200,
            $this->sseHeaders()
        );
    }

    /** 14 kế hoạch gần nhất theo scope. */
    public function history(Request $request): JsonResponse
    {
        $scope = $request->query('scope', 'daily') === 'monthly' ? 'monthly' : 'daily';
        $plans = $request->user()->mealPlans()
            ->where('scope', $scope)
            ->orderByDesc('target_date')
            ->limit(14)
            ->get(['target_date', 'plan', 'updated_at'])
            ->map(fn ($p) => [
                'target_date'  => $p->target_date->toDateString(),
                'plan'         => $p->plan,
                'generated_at' => $p->updated_at?->toIso8601String(),
            ]);

        return response()->json(['plans' => $plans]);
    }

    private function targetDate(string $scope): string
    {
        return $scope === 'monthly'
            ? today()->startOfMonth()->toDateString()
            : today()->addDay()->toDateString();
    }

    private function sseHeaders(): array
    {
        return [
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ];
    }
}
