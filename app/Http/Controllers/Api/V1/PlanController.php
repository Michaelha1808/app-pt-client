<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MealPlan;
use App\Services\MealPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlanController extends Controller
{
    /** Bản nháp vừa sinh sống tối đa 30 phút chờ user bấm "Áp dụng". */
    private const DRAFT_TTL_MINUTES = 30;

    /** Lấy kế hoạch hiện hành + cờ stale. */
    public function show(Request $request, MealPlanService $service): JsonResponse
    {
        $scope      = $this->normalizeScope($request->query('scope'));
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

    /**
     * Sinh kế hoạch mới — SSE 2-phase. CHỈ tạo bản nháp (cache), KHÔNG ghi đè kế hoạch đang
     * dùng: user xem trước rồi bấm "Áp dụng" (apply()) mới lưu. Nhờ vậy tạo lại mà chưa ưng
     * thì kế hoạch cũ vẫn còn nguyên.
     */
    public function generate(Request $request, MealPlanService $service): StreamedResponse
    {
        $request->validate(['scope' => 'nullable|in:daily,weekly,monthly']);
        $scope      = $this->normalizeScope($request->input('scope'));
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

                    // Lưu nháp ngay sau khi có plan: nếu phần diễn giải bên dưới lỗi/đứt mạng
                    // thì user vẫn áp dụng được kế hoạch đã xem.
                    $this->putDraft($user->id, $scope, $targetDate, $plan, $context, null);

                    $reasoning = '';
                    foreach ($service->streamReasoning($context, $plan, $scope) as $delta) {
                        $reasoning .= $delta;
                        echo 'data: ' . json_encode(['type' => 'text', 'delta' => $delta]) . "\n\n";
                        flush();
                    }
                    $this->putDraft($user->id, $scope, $targetDate, $plan, $context, $reasoning);
                } catch (\Throwable $e) {
                    Log::error('Tạo kế hoạch thất bại', [
                        'user_id' => $user->id,
                        'scope'   => $scope,
                        'error'   => $e->getMessage(),
                    ]);
                    report($e);

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

    /**
     * Áp dụng bản nháp vừa sinh → lưu thành kế hoạch đang dùng.
     * Nháp lấy từ cache server (không nhận plan do client gửi lên) để nội dung đúng
     * nguyên bản AI đã sinh và user đã xem.
     */
    public function apply(Request $request): JsonResponse
    {
        $request->validate(['scope' => 'nullable|in:daily,weekly,monthly']);
        $scope = $this->normalizeScope($request->input('scope'));
        $user  = $request->user();

        $draft = Cache::pull($this->draftKey($user->id, $scope));

        if (!$draft) {
            return response()->json([
                'message' => 'Bản kế hoạch đã hết hạn. Bạn hãy tạo lại rồi áp dụng nhé.',
            ], 422);
        }

        // Dùng Carbon (không phải chuỗi 'Y-m-d') cho khoá tìm kiếm: cột được cast 'date' nên
        // chuỗi ngày trần không khớp bản ghi sẵn có trên mọi driver → updateOrCreate sẽ INSERT
        // và đụng unique constraint thay vì ghi đè.
        $record = $user->mealPlans()->updateOrCreate(
            ['scope' => $scope, 'target_date' => Carbon::parse($draft['target_date'])],
            [
                'plan'             => $draft['plan'],
                'context_snapshot' => $draft['context'],
                // cột NOT NULL — buildContext() luôn set, '' chỉ là chốt chặn để không 500
                'data_hash'        => $draft['context']['data_hash'] ?? '',
                'reasoning'        => $draft['reasoning'],
            ]
        );

        return response()->json([
            'message'      => 'Đã áp dụng kế hoạch',
            'plan'         => $record->plan,
            'reasoning'    => $record->reasoning,
            'target_date'  => $record->target_date->toDateString(),
            'generated_at' => $record->updated_at?->toIso8601String(),
        ]);
    }

    /** 14 kế hoạch gần nhất theo scope. */
    public function history(Request $request): JsonResponse
    {
        $scope = $this->normalizeScope($request->query('scope'));
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

    private function normalizeScope(?string $scope): string
    {
        return in_array($scope, ['weekly', 'monthly'], true) ? $scope : 'daily';
    }

    /** @param  array<string,mixed>  $plan  @param  array<string,mixed>  $context */
    private function putDraft(int $userId, string $scope, string $targetDate, array $plan, array $context, ?string $reasoning): void
    {
        Cache::put($this->draftKey($userId, $scope), [
            'target_date' => $targetDate,
            'plan'        => $plan,
            'context'     => $context,
            'reasoning'   => $reasoning,
        ], now()->addMinutes(self::DRAFT_TTL_MINUTES));
    }

    private function draftKey(int $userId, string $scope): string
    {
        return "plan_draft:{$userId}:{$scope}";
    }

    private function targetDate(string $scope): string
    {
        return match ($scope) {
            'monthly' => today()->startOfMonth()->toDateString(),
            'weekly'  => today()->startOfWeek()->toDateString(),   // Thứ 2 đầu tuần hiện tại
            default   => today()->addDay()->toDateString(),        // daily = ngày mai
        };
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
