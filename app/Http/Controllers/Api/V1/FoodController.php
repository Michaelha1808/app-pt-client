<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MealLog;
use App\Services\FoodAnalysisService;
use App\Services\StreakService;
use App\Support\UsageTracker;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FoodController extends Controller
{
    public function analyze(Request $request, FoodAnalysisService $service): StreamedResponse
    {
        $request->validate([
            'image'                  => 'nullable|string',
            'text'                   => 'nullable|string|min:3|max:500',
            'context.today_calories' => 'nullable|integer|min:0|max:10000',
            'context.goal'           => 'nullable|integer|between:1000,5000',
        ]);

        if (!$request->filled('image') && !$request->filled('text')) {
            return response()->stream(function () {
                echo "data: " . json_encode([
                    'type'    => 'error',
                    'message' => 'Phải cung cấp ảnh hoặc mô tả món ăn',
                ]) . "\n\n";
                echo "data: [DONE]\n\n";
            }, 400, $this->sseHeaders());
        }

        UsageTracker::record('food_analyze', $request->user('sanctum')?->id);

        $image   = $request->input('image');
        $text    = $request->input('text');
        $context = $request->input('context', []);
        $context = [
            'today_calories' => (int) ($context['today_calories'] ?? 0),
            'goal'           => (int) ($context['goal'] ?? 2000),
        ];

        return response()->stream(
            function () use ($service, $image, $text, $context) {
                while (ob_get_level()) {
                    ob_end_clean();
                }

                try {
                    // Phase A: structured data ngay lập tức
                    $result = $service->getStructuredData($image, $text, $context);
                    echo "data: " . json_encode(['type' => 'result', 'data' => $result]) . "\n\n";
                    flush();

                    // Không phải món ăn → không stream lời khuyên dinh dưỡng
                    if ($result['food_name'] === 'Không phải món ăn' || $result['confidence'] <= 0) {
                        echo "data: " . json_encode([
                            'type'  => 'text',
                            'delta' => 'Mình chỉ nhận diện được món ăn hoặc đồ uống thôi nhé. Bạn vui lòng chụp/nhập lại một món ăn để mình phân tích dinh dưỡng. 🥗',
                        ]) . "\n\n";
                        flush();
                    } else {
                        // Phase B: stream lời khuyên
                        foreach ($service->streamAdvice($result['food_name'], $result['calories'], $context) as $delta) {
                            echo "data: " . json_encode(['type' => 'text', 'delta' => $delta]) . "\n\n";
                            flush();
                        }
                    }
                } catch (\Throwable $e) {
                    echo "data: " . json_encode([
                        'type'    => 'error',
                        'message' => 'Không thể phân tích món ăn. Vui lòng thử lại.',
                    ]) . "\n\n";
                    flush();
                }

                echo "data: [DONE]\n\n";
                flush();
            },
            200,
            $this->sseHeaders()
        );
    }

    public function detect(Request $request, FoodAnalysisService $service): JsonResponse
    {
        $request->validate([
            'image' => 'nullable|string',
            'text'  => 'nullable|string|max:500',
        ]);

        if (!$request->filled('image') && !$request->filled('text')) {
            return response()->json(['message' => 'Phải cung cấp ảnh hoặc mô tả bữa ăn'], 422);
        }

        UsageTracker::record('food_detect', $request->user('sanctum')?->id);

        try {
            $dishes = $service->detectDishes($request->input('image'), $request->input('text'));
            return response()->json(['dishes' => $dishes]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Không thể nhận diện món ăn. Vui lòng thử lại.'], 500);
        }
    }

    public function adviseMeal(Request $request, FoodAnalysisService $service): StreamedResponse
    {
        $request->validate([
            'dishes'            => 'required|array|min:1|max:30',
            'dishes.*.name'     => 'required|string|max:200',
            'dishes.*.calories' => 'required|integer|min:0|max:10000',
            'total_calories'    => 'nullable|integer|min:0',
            'context.today_calories' => 'nullable|integer|min:0|max:10000',
            'context.goal'           => 'nullable|integer|between:1000,5000',
        ]);

        $items = array_map(
            fn ($d) => ['name' => (string) $d['name'], 'calories' => (int) $d['calories']],
            $request->input('dishes')
        );
        $total   = (int) ($request->input('total_calories') ?? array_sum(array_column($items, 'calories')));
        $context = [
            'today_calories' => (int) $request->input('context.today_calories', 0),
            'goal'           => (int) $request->input('context.goal', 2000),
        ];

        return response()->stream(
            function () use ($service, $items, $total, $context) {
                while (ob_get_level()) {
                    ob_end_clean();
                }
                try {
                    foreach ($service->streamMealAdvice($items, $total, $context) as $delta) {
                        echo 'data: ' . json_encode(['type' => 'text', 'delta' => $delta]) . "\n\n";
                        flush();
                    }
                } catch (\Throwable $e) {
                    echo 'data: ' . json_encode(['type' => 'error', 'message' => 'Không thể tạo nhận xét.']) . "\n\n";
                    flush();
                }
                echo "data: [DONE]\n\n";
                flush();
            },
            200,
            $this->sseHeaders()
        );
    }

    public function log(Request $request, StreakService $streakService): JsonResponse
    {
        $data = $request->validate([
            'food_name' => 'required|string|max:200',
            'serving'   => 'nullable|string|max:100',
            'calories'  => 'required|integer|min:0|max:10000',
            'protein'   => 'required|integer|min:0',
            'carbs'     => 'required|integer|min:0',
            'fat'       => 'required|integer|min:0',
            'sodium'    => 'required|integer|min:0',
        ]);

        $user = $request->user();
        $log  = $user->mealLogs()->create($data);

        $streak = $streakService->recordMealActivity($user->load('streakMilestones', 'notificationSubscriptions'));

        return response()->json([
            'message' => 'Đã lưu bữa ăn',
            'id'      => $log->id,
            'streak'  => $streak,
        ], 201);
    }

    /**
     * Lưu nhiều món cùng lúc (mâm/bàn tiệc) — atomic, cập nhật streak đúng 1 lần.
     */
    public function logBatch(Request $request, StreakService $streakService): JsonResponse
    {
        $data = $request->validate([
            'meals'             => 'required|array|min:1|max:30',
            'meals.*.food_name' => 'required|string|max:200',
            'meals.*.serving'   => 'nullable|string|max:100',
            'meals.*.calories'  => 'required|integer|min:0|max:10000',
            'meals.*.protein'   => 'required|integer|min:0',
            'meals.*.carbs'     => 'required|integer|min:0',
            'meals.*.fat'       => 'required|integer|min:0',
            'meals.*.sodium'    => 'required|integer|min:0',
        ]);

        $user = $request->user();

        $ids = DB::transaction(function () use ($user, $data) {
            return collect($data['meals'])
                ->map(fn ($m) => $user->mealLogs()->create($m)->id)
                ->all();
        });

        // recordMealActivity idempotent theo ngày → gọi 1 lần là đủ
        $streak = $streakService->recordMealActivity($user->load('streakMilestones', 'notificationSubscriptions'));

        return response()->json([
            'message' => 'Đã lưu ' . count($ids) . ' món',
            'ids'     => $ids,
            'streak'  => $streak,
        ], 201);
    }

    public function todayStats(Request $request): JsonResponse
    {
        $logs = $request->user()
            ->mealLogs()
            ->whereDate('logged_at', today())
            ->orderBy('logged_at')
            ->get(['id', 'food_name', 'serving', 'calories', 'protein', 'carbs', 'fat', 'sodium', 'logged_at']);

        return response()->json([
            'total_calories' => $logs->sum('calories'),
            'total_protein'  => $logs->sum('protein'),
            'total_carbs'    => $logs->sum('carbs'),
            'total_fat'      => $logs->sum('fat'),
            'meals'          => $logs->map(fn ($log) => [
                'id'        => $log->id,
                'food_name' => $log->food_name,
                'serving'   => $log->serving,
                'calories'  => $log->calories,
                'protein'   => $log->protein,
                'carbs'     => $log->carbs,
                'fat'       => $log->fat,
                'sodium'    => $log->sodium,
                'logged_at' => $log->logged_at->format('H:i'),
            ]),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $dateStr    = $request->query('date', today()->toDateString());
        $targetDate = Carbon::parse($dateStr);
        $user       = $request->user();

        $meals = $user->mealLogs()
            ->whereDate('logged_at', $targetDate)
            ->orderBy('logged_at')
            ->get(['id', 'food_name', 'serving', 'calories', 'protein', 'carbs', 'fat', 'sodium', 'logged_at']);

        $week = collect(range(6, 0))->map(function ($daysAgo) use ($user) {
            $day  = today()->subDays($daysAgo);
            $cals = $user->mealLogs()->whereDate('logged_at', $day)->sum('calories');
            return [
                'date'           => $day->toDateString(),
                'day_label'      => ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'][$day->dayOfWeek],
                'total_calories' => (int) $cals,
            ];
        })->values();

        return response()->json([
            'date'           => $targetDate->toDateString(),
            'total_calories' => (int) $meals->sum('calories'),
            'total_protein'  => (int) $meals->sum('protein'),
            'total_carbs'    => (int) $meals->sum('carbs'),
            'total_fat'      => (int) $meals->sum('fat'),
            'meals'          => $meals->map(fn ($log) => [
                'id'        => $log->id,
                'food_name' => $log->food_name,
                'serving'   => $log->serving,
                'calories'  => $log->calories,
                'protein'   => $log->protein,
                'carbs'     => $log->carbs,
                'fat'       => $log->fat,
                'sodium'    => $log->sodium,
                'logged_at' => $log->logged_at->format('H:i'),
            ]),
            'week' => $week,
        ]);
    }

    public function deleteLog(Request $request, MealLog $log): JsonResponse
    {
        abort_if($log->user_id !== $request->user()->id, 403);
        $log->delete();
        return response()->json(['message' => 'Đã xóa bữa ăn']);
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
