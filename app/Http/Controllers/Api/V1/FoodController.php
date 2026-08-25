<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FoodDetectionSample;
use App\Models\MealLog;
use App\Services\DishCatalogService;
use App\Services\FoodAnalysisService;
use App\Services\FoodSampleService;
use App\Services\PreferenceService;
use App\Services\QuickLogService;
use App\Services\StreakService;
use App\Support\UsageTracker;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FoodController extends Controller
{
    /** Admin tắt ai.food_analysis_enabled trong Settings → chặn các endpoint gọi AI nhận diện món. */
    private function foodAnalysisDisabled(): ?JsonResponse
    {
        if (app(\App\Services\SettingsService::class)->get('ai.food_analysis_enabled', true) !== true) {
            return response()->json([
                'detail' => 'Tính năng phân tích món ăn bằng AI đang tạm tắt. Vui lòng quay lại sau.',
                'code'   => 'feature_disabled',
            ], 503);
        }
        return null;
    }

    // DEFENSE: endpoint phân tích 1 món — SSE stream Phase A (calo) + Phase B (lời khuyên); toggle qua ai.food_analysis_enabled
    public function analyze(Request $request, FoodAnalysisService $service, PreferenceService $preferences, DishCatalogService $catalog): StreamedResponse|JsonResponse
    {
        if ($disabled = $this->foodAnalysisDisabled()) return $disabled;

        $request->validate([
            'image'                  => 'nullable|string',
            // DEFENSE: giới hạn text phân tích món — min 3 / max 500 ký tự
            'text'                   => 'nullable|string|min:3|max:500',
            'context.today_calories' => 'nullable|integer|min:0|max:10000',
            'context.goal'           => 'nullable|integer|between:1000,5000',
        ]);

        if (!$request->filled('image') && !$request->filled('text')) {
            // DEFENSE: text lỗi thiếu input analyze — cần ảnh hoặc mô tả
            return response()->stream(function () {
                echo "data: " . json_encode([
                    'type'    => 'error',
                    'message' => 'Phải cung cấp ảnh hoặc mô tả món ăn',
                ]) . "\n\n";
                echo "data: [DONE]\n\n";
            }, 400, $this->sseHeaders());
        }

        $user = $request->user('sanctum');
        UsageTracker::record('food_analyze', $user?->id);

        $image   = $request->input('image');
        $text    = $request->input('text');
        $context = $request->input('context', []);
        $context = [
            'today_calories' => (int) ($context['today_calories'] ?? 0),
            'goal'           => (int) ($context['goal'] ?? 2000),
        ];
        if ($user) {
            $context['pref_constraints'] = $preferences->promptBlock($user);
        }

        return response()->stream(
            function () use ($service, $catalog, $image, $text, $context) {
                while (ob_get_level()) {
                    ob_end_clean();
                }

                try {
                    // Phase A: structured data ngay lập tức
                    // DEFENSE: gọi Gemini phân tích món — trả JSON calo/macro/confidence
                    $result = $service->getStructuredData($image, $text, $context);

                    // Grounding: nếu tên món khớp thư viện `dishes`, thay calo/macro/tên
                    // bằng giá trị chuẩn thay vì để AI tự đoán. Bỏ qua nhánh "Không phải món ăn"
                    // (đã xử lý riêng ở dưới).
                    // DEFENSE: grounding calo — thay số AI đoán bằng số chuẩn từ bảng `dishes` nếu khớp
                    if (($result['food_name'] ?? '') !== 'Không phải món ăn') {
                        $result = $catalog->groundOne($result);
                    } else {
                        $result['source']  = 'ai';
                        $result['dish_id'] = null;
                    }

                    // Sanity check Atwater: cảnh báo user khi macro/kcal không cân đối.
                    $result['warning'] = \App\Support\NutritionValidator::warning(
                        (int) $result['calories'],
                        (int) $result['protein'],
                        (int) $result['carbs'],
                        (int) $result['fat'],
                    );

                    echo "data: " . json_encode(['type' => 'result', 'data' => $result]) . "\n\n";
                    flush();

                    // Không phải món ăn → không stream lời khuyên dinh dưỡng
                    // DEFENSE: text từ chối không phải món ăn — hiển thị khi AI nhận ra ảnh không có món
                    if ($result['food_name'] === 'Không phải món ăn' || $result['confidence'] <= 0) {
                        echo "data: " . json_encode([
                            'type'  => 'text',
                            'delta' => 'Mình chỉ nhận diện được món ăn hoặc đồ uống thôi nhé. Bạn vui lòng chụp/nhập lại một món ăn để mình phân tích dinh dưỡng. 🥗',
                        ]) . "\n\n";
                        flush();
                    } else {
                        // Phase B: stream lời khuyên
                        // DEFENSE: stream lời khuyên món — gọi Gemini SSE trả từng chunk text
                        foreach ($service->streamAdvice($result['food_name'], $result['calories'], $context, $result) as $delta) {
                            echo "data: " . json_encode(['type' => 'text', 'delta' => $delta]) . "\n\n";
                            flush();
                        }
                    }
                } catch (\Throwable $e) {
                    // DEFENSE: text lỗi phân tích món — hiển thị khi Gemini fail hoặc timeout
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

    /**
     * Sinh lại lời khuyên cho ĐÚNG tên món/calo user vừa sửa — dùng khi AI nhận diện sai và
     * user chỉnh lại tên trong Result.vue. Trước đây không có endpoint này nên sửa tên chỉ
     * update state ở FE, lời khuyên hiển thị vẫn "đóng băng" theo tên AI đoán sai ban đầu
     * (bug thật đã gặp). Tách riêng khỏi analyze() vì không cần chạy lại Phase A (nhận diện
     * ảnh) — chỉ cần Phase B (lời khuyên) với input đã có sẵn.
     */
    public function advise(Request $request, FoodAnalysisService $service, PreferenceService $preferences): StreamedResponse|JsonResponse
    {
        if ($disabled = $this->foodAnalysisDisabled()) return $disabled;

        $request->validate([
            'food_name'               => 'required|string|max:200',
            'calories'                => 'required|integer|min:0|max:10000',
            'serving'                 => 'nullable|string|max:200',
            'protein'                 => 'nullable|integer|min:0|max:1000',
            'carbs'                   => 'nullable|integer|min:0|max:2000',
            'fat'                     => 'nullable|integer|min:0|max:1000',
            'sodium'                  => 'nullable|integer|min:0|max:20000',
            'context.today_calories'  => 'nullable|integer|min:0|max:10000',
            'context.goal'            => 'nullable|integer|between:1000,5000',
        ]);

        $foodName = (string) $request->input('food_name');
        $calories = (int) $request->input('calories');
        $context  = [
            'today_calories' => (int) $request->input('context.today_calories', 0),
            'goal'           => (int) $request->input('context.goal', 2000),
        ];
        // Khẩu phần/macro user vừa sửa — optional để client cũ (chỉ gửi tên + calo) vẫn chạy.
        $nutrition = [
            'serving' => (string) $request->input('serving', ''),
            'protein' => (int) $request->input('protein', 0),
            'carbs'   => (int) $request->input('carbs', 0),
            'fat'     => (int) $request->input('fat', 0),
            'sodium'  => (int) $request->input('sodium', 0),
        ];

        $user = $request->user('sanctum');
        if ($user) {
            $context['pref_constraints'] = $preferences->promptBlock($user);
        }

        return response()->stream(
            function () use ($service, $foodName, $calories, $context, $nutrition) {
                while (ob_get_level()) {
                    ob_end_clean();
                }
                try {
                    foreach ($service->streamAdvice($foodName, $calories, $context, $nutrition) as $delta) {
                        echo "data: " . json_encode(['type' => 'text', 'delta' => $delta]) . "\n\n";
                        flush();
                    }
                } catch (\Throwable $e) {
                    echo "data: " . json_encode([
                        'type'    => 'error',
                        'message' => 'Không thể tạo lời khuyên. Vui lòng thử lại.',
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

    /**
     * Ước tính lại calo + macro cho tên món/khẩu phần user vừa sửa.
     *
     * Bổ sung cho advise(): trước đây sửa tên món sai chỉ sinh lại LỜI KHUYÊN, còn con số
     * calo/macro vẫn là của món AI đoán sai → nhật ký lưu sai số liệu. Endpoint này trả JSON
     * (không stream) để FE áp số mới vào form trước, rồi mới gọi advise() sinh lời khuyên
     * khớp với số đó.
     */
    public function estimate(Request $request, FoodAnalysisService $service, DishCatalogService $catalog): JsonResponse
    {
        if ($disabled = $this->foodAnalysisDisabled()) return $disabled;

        $request->validate([
            'food_name'  => 'required|string|min:1|max:200',
            'serving'    => 'nullable|string|max:200',
            'unit_label' => 'nullable|string|max:50',
        ]);

        UsageTracker::record('food_estimate', $request->user('sanctum')?->id);

        $foodName  = (string) $request->input('food_name');
        $serving   = $request->input('serving') ?: null;
        $unitLabel = $request->input('unit_label') ?: null;

        // Grounding: nếu khớp thư viện và user không đổi sang đơn vị khác → trả thẳng
        // số chuẩn, khỏi gọi AI. Đổi đơn vị (vd catalog "tô" → user "chén") thì để AI
        // tính lại vì tỉ lệ không đơn giản.
        $match = $catalog->match($foodName);
        if ($match && (!$unitLabel || $unitLabel === $match->unit_label)) {
            return response()->json([
                'serving'  => $match->serving,
                'calories' => $match->calories,
                'protein'  => $match->protein,
                'carbs'    => $match->carbs,
                'fat'      => $match->fat,
                'sodium'   => $match->sodium,
                'source'   => 'catalog',
                'dish_id'  => $match->id,
                'warning'  => null,
            ]);
        }

        try {
            $data = $service->estimateNutrition($foodName, $serving, $unitLabel);
            $data['source']  = 'ai';
            $data['dish_id'] = null;
            $data['warning'] = \App\Support\NutritionValidator::warning(
                (int) $data['calories'],
                (int) $data['protein'],
                (int) $data['carbs'],
                (int) $data['fat'],
            );
            return response()->json($data);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Không ước tính được dinh dưỡng cho món này. Bạn có thể nhập tay.',
            ], 502);
        }
    }

    // DEFENSE: endpoint nhận diện nhiều món — trả mảng dishes, kèm detection_id để feedback dataset
    public function detect(
        Request $request,
        FoodAnalysisService $service,
        FoodSampleService $samples,
        DishCatalogService $catalog,
    ): JsonResponse {
        if ($disabled = $this->foodAnalysisDisabled()) return $disabled;

        $request->validate([
            'image' => 'nullable|string',
            // DEFENSE: giới hạn text detect — max 500 ký tự mô tả bữa ăn
            'text'  => 'nullable|string|max:500',
        ]);

        if (!$request->filled('image') && !$request->filled('text')) {
            return response()->json(['message' => 'Phải cung cấp ảnh hoặc mô tả bữa ăn'], 422);
        }

        UsageTracker::record('food_detect', $request->user('sanctum')?->id);

        try {
            $image    = $request->input('image');
            $text     = $request->input('text');
            // Gợi ý tên: danh sách món chuẩn + cặp dễ nhầm học từ dataset → tăng tỉ lệ khớp thư viện.
            // DEFENSE: gọi Gemini detect nhiều món — kèm hint tên từ catalog + correction từ dataset
            $aiDishes = $service->detectDishes(
                $image,
                $text,
                $catalog->names(),
                $samples->nameCorrectionExamples(),
            );

            // Thu thập mẫu (AI đoán RAW) để cải thiện model — best-effort, không chặn luồng chính.
            // DEFENSE: lưu sample dataset — dùng để cải thiện model (Admin/Dataset xem sample)
            $sample = $samples->capture(
                $image,
                $text,
                $aiDishes,
                $request->user('sanctum')?->id,
                $service->modelName(),
            );

            // Grounding: thay calo/macro/tên bằng giá trị chuẩn từ thư viện nutrition (nếu khớp).
            // DEFENSE: grounding nhiều món — mỗi món khớp catalog thì dùng số chuẩn
            $dishes = $catalog->ground($aiDishes);

            return response()->json([
                'dishes'       => $dishes,
                'detection_id' => $sample?->id,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Không thể nhận diện món ăn. Vui lòng thử lại.'], 500);
        }
    }

    /**
     * Ghi nhận kết quả user chốt cho 1 lần detect (AI đoán vs user sửa) → dataset cải thiện model.
     * Public (guest cũng gửi được), không chặn nếu lỗi.
     */
    public function detectFeedback(Request $request, FoodDetectionSample $sample, FoodSampleService $samples): JsonResponse
    {
        $data = $request->validate([
            'saved'               => 'nullable|boolean',
            'dishes'              => 'required|array|max:30',
            'dishes.*.food_name'  => 'required|string|max:200',
            'dishes.*.calories'   => 'required|integer|min:0|max:10000',
            'dishes.*.quantity'   => 'required|numeric|min:0|max:99',
            'dishes.*.selected'   => 'required|boolean',
        ]);

        $samples->recordFeedback($sample, $data['dishes'], (bool) ($data['saved'] ?? false));

        return response()->json(['message' => 'ok']);
    }

    public function adviseMeal(Request $request, FoodAnalysisService $service, PreferenceService $preferences): StreamedResponse|JsonResponse
    {
        if ($disabled = $this->foodAnalysisDisabled()) return $disabled;

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

        // Không bắt buộc auth (khách vẫn dùng được) — nhưng nếu đã đăng nhập thì kèm dị ứng
        // để tư vấn không "mù" như trước (gợi ý món có thể chứa nguyên liệu người dùng dị ứng).
        $user = $request->user('sanctum');
        if ($user) {
            $context['pref_constraints'] = $preferences->promptBlock($user);
        }

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

    // DEFENSE: endpoint lưu 1 bữa ăn — INSERT vào `meal_logs` + cập nhật streak
    public function log(Request $request, StreakService $streakService): JsonResponse
    {
        $data = $request->validate([
            // DEFENSE: giới hạn tên món khi log — max 200 ký tự
            'food_name' => 'required|string|max:200',
            'serving'   => 'nullable|string|max:100',
            // DEFENSE: giới hạn calo khi log 1 bữa — max 10000 kcal/bữa
            'calories'  => 'required|integer|min:0|max:10000',
            'protein'   => 'required|integer|min:0',
            'carbs'     => 'required|integer|min:0',
            'fat'       => 'required|integer|min:0',
            'sodium'    => 'required|integer|min:0',
            'ai_advice' => 'nullable|string|max:5000',       // lời khuyên AI để xem lại trong lịch sử
            // DEFENSE: giới hạn kích thước ảnh — 8MB base64 (~6MB nhị phân)
            'image'     => 'nullable|string|max:8000000',   // data URL base64 ảnh đã chụp
        ]);

        $imagePath = $this->storeMealImage($data['image'] ?? null);
        unset($data['image']);

        $user = $request->user();
        $log  = $user->mealLogs()->create([...$data, 'image_path' => $imagePath]);

        // DEFENSE: xoá cache habit sau khi log — để lần chat sau AI thấy thói quen mới
        app(PreferenceService::class)->bustHabitCache($user->id);

        // DEFENSE: cập nhật streak sau khi log — +1 nếu hôm qua có log, reset nếu quá 2 ngày
        $streak = $streakService->recordMealActivity($user->load('streakMilestones', 'notificationSubscriptions'));

        // DEFENSE: text lưu bữa ăn thành công — hiển thị toast sau khi log
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
            'ai_advice'         => 'nullable|string|max:5000',      // 1 phân tích chung cho cả bữa
            'image'             => 'nullable|string|max:8000000',   // 1 ảnh chung cho cả mâm
        ]);

        $user = $request->user();

        // Cả mâm dùng chung 1 ảnh chụp → lưu 1 lần, gán cho mọi món.
        $imagePath = $this->storeMealImage($request->input('image'));
        $aiAdvice  = $data['ai_advice'] ?? null;   // lưu cùng phân tích cho mọi món → xem lại theo cả bữa

        $ids = DB::transaction(function () use ($user, $data, $imagePath, $aiAdvice) {
            return collect($data['meals'])
                ->map(fn ($m) => $user->mealLogs()->create([
                    ...$m,
                    'image_path' => $imagePath,
                    'ai_advice'  => $aiAdvice,
                ])->id)
                ->all();
        });

        app(PreferenceService::class)->bustHabitCache($user->id);

        // recordMealActivity idempotent theo ngày → gọi 1 lần là đủ
        $streak = $streakService->recordMealActivity($user->load('streakMilestones', 'notificationSubscriptions'));

        return response()->json([
            'message' => 'Đã lưu ' . count($ids) . ' món',
            'ids'     => $ids,
            'streak'  => $streak,
        ], 201);
    }

    /**
     * Ghi lại 1 bữa đã log trước đó thành bữa mới ngay bây giờ — không gọi AI.
     */
    public function relog(Request $request, MealLog $log, StreakService $streakService): JsonResponse
    {
        abort_if($log->user_id !== $request->user()->id, 404);

        $data = $request->validate([
            'serving' => 'sometimes|nullable|string|max:100',
        ]);

        $user = $request->user();
        $new  = $user->mealLogs()->create([
            'food_name'  => $log->food_name,
            'serving'    => array_key_exists('serving', $data) ? $data['serving'] : $log->serving,
            'calories'   => $log->calories,
            'protein'    => $log->protein,
            'carbs'      => $log->carbs,
            'fat'        => $log->fat,
            'sodium'     => $log->sodium,
            'ai_advice'  => $log->ai_advice,
            'image_path' => $log->image_path,
        ]);

        app(PreferenceService::class)->bustHabitCache($user->id);
        $streak = $streakService->recordMealActivity($user->load('streakMilestones', 'notificationSubscriptions'));

        UsageTracker::record('quick_log_relog', $user->id);

        return response()->json([
            'message' => 'Đã lưu bữa ăn',
            'id'      => $new->id,
            'streak'  => $streak,
        ], 201);
    }

    /**
     * Món ăn "thường ăn" trong 30 ngày qua, gợi ý theo khung giờ hiện tại (FE truyền slot).
     */
    public function frequent(Request $request, QuickLogService $quickLog): JsonResponse
    {
        $request->validate([
            'slot'  => 'sometimes|in:morning,noon,evening',
            'limit' => 'sometimes|integer|min:1|max:20',
        ]);

        $items = $quickLog->frequent(
            $request->user(),
            $request->query('slot'),
            (int) $request->query('limit', 8),
        );

        return response()->json(['items' => $items]);
    }

    public function todayStats(Request $request): JsonResponse
    {
        $logs = $request->user()
            ->mealLogs()
            ->whereDate('logged_at', today())
            ->orderBy('logged_at')
            ->get(['id', 'food_name', 'serving', 'image_path', 'calories', 'protein', 'carbs', 'fat', 'sodium', 'ai_advice', 'logged_at']);

        // Calo đốt hôm nay (mọi nguồn: manual + provider) → FE tính net calories.
        $caloriesBurned = (int) $request->user()
            ->healthActivities()
            ->whereDate('started_at', today())
            ->sum('calories');

        return response()->json([
            'total_calories'   => $logs->sum('calories'),
            'total_protein'    => $logs->sum('protein'),
            'total_carbs'      => $logs->sum('carbs'),
            'total_fat'        => $logs->sum('fat'),
            'calories_burned'  => $caloriesBurned,
            'meals'            => $logs->map(fn ($log) => [
                'id'        => $log->id,
                'food_name' => $log->food_name,
                'serving'   => $log->serving,
                'image_url' => $log->image_url,
                'calories'  => $log->calories,
                'protein'   => $log->protein,
                'carbs'     => $log->carbs,
                'fat'       => $log->fat,
                'sodium'    => $log->sodium,
                'ai_advice' => $log->ai_advice,
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
            ->get(['id', 'food_name', 'serving', 'image_path', 'calories', 'protein', 'carbs', 'fat', 'sodium', 'ai_advice', 'logged_at']);

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
                'image_url' => $log->image_url,
                'calories'  => $log->calories,
                'protein'   => $log->protein,
                'carbs'     => $log->carbs,
                'fat'       => $log->fat,
                'sodium'    => $log->sodium,
                'ai_advice' => $log->ai_advice,
                'logged_at' => $log->logged_at->format('H:i'),
            ]),
            'week' => $week,
        ]);
    }

    /**
     * Lịch sử GỘP ăn uống + tập luyện theo khoảng ngày (from..to).
     * Trả timeline sắp xếp mới→cũ + tổng hợp calo nạp/đốt từng ngày (cho biểu đồ).
     * Mặc định: 7 ngày gần nhất.
     */
    public function timeline(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date',
        ]);

        $user = $request->user();
        $to   = $request->filled('to')   ? Carbon::parse($request->query('to'))->endOfDay()     : today()->endOfDay();
        $from = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay()  : today()->subDays(6)->startOfDay();

        // An toàn: from ≤ to, tối đa 92 ngày để tránh truy vấn quá lớn.
        if ($from->gt($to)) [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        if ($from->diffInDays($to) > 92) $from = $to->copy()->subDays(92)->startOfDay();

        $meals = $user->mealLogs()
            ->whereBetween('logged_at', [$from, $to])
            ->orderBy('logged_at')
            ->get(['id', 'food_name', 'serving', 'image_path', 'calories', 'protein', 'carbs', 'fat', 'sodium', 'ai_advice', 'logged_at']);

        $activities = $user->healthActivities()
            ->whereBetween('started_at', [$from, $to])
            ->orderBy('started_at')
            ->get(['id', 'provider', 'source', 'type', 'name', 'started_at', 'duration_seconds', 'distance_meters', 'calories']);

        // Timeline gộp, mới → cũ.
        $entries = collect();
        foreach ($meals as $m) {
            $entries->push([
                'kind'      => 'meal',
                'id'        => $m->id,
                'food_name' => $m->food_name,
                'serving'   => $m->serving,
                'image_url' => $m->image_url,
                'calories'  => $m->calories,
                'protein'   => $m->protein,
                'carbs'     => $m->carbs,
                'fat'       => $m->fat,
                'sodium'    => $m->sodium,
                'ai_advice' => $m->ai_advice,
                'at'        => $m->logged_at->toIso8601String(),
                'date'      => $m->logged_at->toDateString(),
                'time'      => $m->logged_at->format('H:i'),
            ]);
        }
        foreach ($activities as $a) {
            $entries->push([
                'kind'             => 'activity',
                'id'               => $a->id,
                'provider'         => $a->provider,
                'source'           => $a->source,
                'type'             => $a->type,
                'name'             => $a->name,
                'duration_seconds' => $a->duration_seconds,
                'distance_meters'  => $a->distance_meters,
                'calories'         => $a->calories,
                'at'               => $a->started_at->toIso8601String(),
                'date'             => $a->started_at->toDateString(),
                'time'             => $a->started_at->format('H:i'),
            ]);
        }
        $entries = $entries->sortByDesc('at')->values();

        // Tổng hợp theo từng ngày trong khoảng (cho biểu đồ + strip ngày).
        // (int) bắt buộc: diffInDays() giữa startOfDay và endOfDay cùng 1 ngày trả về
        // float xấp xỉ 1 (vd 0.999999...) thay vì 0 nguyên — cộng 1 rồi đưa vào range()
        // làm PHP báo lỗi "step must be less than range" ngay khi lọc đúng 1 ngày (mode=day).
        $dayCount = (int) $from->diffInDays($to) + 1;
        $days = collect(range(0, $dayCount - 1))->map(function ($i) use ($from, $meals, $activities) {
            $day  = $from->copy()->addDays($i);
            $dstr = $day->toDateString();
            return [
                'date'       => $dstr,
                'day_label'  => ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'][$day->dayOfWeek],
                'day_num'    => $day->day,
                'intake'     => (int) $meals->filter(fn ($m) => $m->logged_at->toDateString() === $dstr)->sum('calories'),
                'burned'     => (int) $activities->filter(fn ($a) => $a->started_at->toDateString() === $dstr)->sum('calories'),
            ];
        })->values();

        return response()->json([
            'from'           => $from->toDateString(),
            'to'             => $to->toDateString(),
            'total_intake'   => (int) $meals->sum('calories'),
            'total_burned'   => (int) $activities->sum('calories'),
            'total_protein'  => (int) $meals->sum('protein'),
            'total_carbs'    => (int) $meals->sum('carbs'),
            'total_fat'      => (int) $meals->sum('fat'),
            'meal_count'     => $meals->count(),
            'activity_count' => $activities->count(),
            'days'           => $days,
            'entries'        => $entries,
        ]);
    }

    public function deleteLog(Request $request, MealLog $log): JsonResponse
    {
        abort_if($log->user_id !== $request->user()->id, 403);

        // Xoá luôn file ảnh nếu không còn bản ghi nào khác dùng chung (mâm cùng ảnh).
        if ($log->image_path
            && !MealLog::where('image_path', $log->image_path)->whereKeyNot($log->id)->exists()) {
            Storage::disk('public')->delete($log->image_path);
        }

        $log->delete();
        return response()->json(['message' => 'Đã xóa bữa ăn']);
    }

    /**
     * Giải mã data URL base64 → lưu vào disk public (thư mục meals/), trả path tương đối.
     * Trả null nếu không có ảnh hoặc dữ liệu không hợp lệ.
     */
    private function storeMealImage(?string $dataUrl): ?string
    {
        if (!$dataUrl || !preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,(.+)$/', $dataUrl, $m)) {
            return null;
        }

        $binary = base64_decode($m[2], true);
        if ($binary === false || strlen($binary) > 5 * 1024 * 1024) {
            return null;
        }

        $ext  = $m[1] === 'jpg' ? 'jpeg' : $m[1];
        $path = 'meals/' . Str::uuid()->toString() . '.' . $ext;
        Storage::disk('public')->put($path, $binary);

        return $path;
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
