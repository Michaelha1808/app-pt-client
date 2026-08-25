<?php

namespace App\Services;

use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class MealPlanService
{
    private Client $http;
    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(private PreferenceService $preferences, private WeightService $weightService)
    {
        $this->apiKey = config('services.gemini.key');
        $this->model  = config('services.gemini.model', 'gemini-2.0-flash');
        $this->http   = new Client(['timeout' => 45]);
    }

    /**
     * Ghi log chi tiết khi gọi Gemini thất bại — kèm HTTP status + response body
     * (thường chứa lý do thật: hết quota, rate limit 429, key sai...).
     * Không đổi thông báo phía client.
     */
    private function logGeminiFailure(string $where, \Throwable $e): void
    {
        $status = null;
        $body   = null;
        if ($e instanceof RequestException && $e->hasResponse()) {
            $status = $e->getResponse()->getStatusCode();
            $body   = mb_substr((string) $e->getResponse()->getBody(), 0, 1500);
        }

        Log::error("Gemini API thất bại [{$where}]", [
            'model'   => $this->model,
            'status'  => $status,
            'message' => $e->getMessage(),
            'body'    => $body,
        ]);
    }

    /**
     * Tính các chỉ số dẫn xuất từ DB + data_hash để phát hiện stale.
     *
     * @throws \RuntimeException nếu hồ sơ chưa đủ để tính BMR
     * @return array<string,mixed>
     */
    public function buildContext(User $user, string $scope): array
    {
        $age    = $user->birth_year ? (int) date('Y') - (int) $user->birth_year : null;
        $weight = $user->weight_kg ? (float) $user->weight_kg : null;
        $height = $user->height_cm ? (float) $user->height_cm : null;

        if (!$age || !$weight || !$height) {
            throw new \RuntimeException('Cần hoàn thiện hồ sơ (chiều cao, cân nặng, năm sinh) để tạo kế hoạch.');
        }

        $gender = $user->gender ?? 'other';
        $goal   = (int) ($user->calorie_goal ?? 2000);
        // BMR Mifflin-St Jeor + TDEE theo PAL user chọn (WHO/FAO 2001) — trước
        // đây cứng 1.375 nên VĐV nhận TDEE tính như dân văn phòng.
        $bmr    = \App\Support\NutritionStandard::bmr($weight, $height, $age, $gender);
        $tdee   = \App\Support\NutritionStandard::tdee($bmr, $user->activity_level);

        $windowDays = $scope === 'monthly' ? 30 : 7;
        $logs = $user->mealLogs()
            ->where('logged_at', '>=', today()->subDays($windowDays - 1)->startOfDay())
            ->get();
        $byDay      = $logs->groupBy(fn ($l) => $l->logged_at->toDateString());
        $daysLogged = $byDay->count();

        $avgCalories = $daysLogged ? (int) round($logs->sum('calories') / $daysLogged) : 0;
        $avgProtein  = $daysLogged ? (int) round($logs->sum('protein') / $daysLogged) : 0;
        $avgCarbs    = $daysLogged ? (int) round($logs->sum('carbs') / $daysLogged) : 0;
        $avgFat      = $daysLogged ? (int) round($logs->sum('fat') / $daysLogged) : 0;

        // Độ tuân thủ: số ngày trong ±10% mục tiêu / số ngày có log
        $okDays = $byDay->filter(function ($dayLogs) use ($goal) {
            $cals = $dayLogs->sum('calories');
            return abs($cals - $goal) <= $goal * 0.10;
        })->count();
        $adherence = $daysLogged ? (int) round($okDays / $daysLogged * 100) : 0;

        // Xu hướng: tuần này vs tuần trước (chỉ daily/7d có ý nghĩa, monthly dùng nửa kỳ)
        $half       = (int) ceil($windowDays / 2);
        $recentCals = $logs->where('logged_at', '>=', today()->subDays($half - 1)->startOfDay())->sum('calories');
        $olderCals  = $logs->sum('calories') - $recentCals;
        $trend = $olderCals === 0
            ? 'ổn định'
            : ($recentCals > $olderCals * 1.1 ? 'tăng' : ($recentCals < $olderCals * 0.9 ? 'giảm' : 'ổn định'));

        $avgWater = (int) round(
            $user->waterLogs()
                ->where('logged_at', '>=', today()->subDays($windowDays - 1)->startOfDay())
                ->sum('amount_ml') / max(1, $daysLogged)
        );

        // Xu hướng cân nặng 30 ngày — giúp AI điều chỉnh calo kế hoạch theo tiến độ thực tế thay vì chỉ theo goal tĩnh.
        $weightTrend = $this->weightService->history($user, 30)['trend'] ?? null;
        $weightTrendLine = $weightTrend
            ? sprintf(
                'Xu hướng cân nặng: %.1fkg hiện tại, thay đổi %.1fkg trong 30 ngày qua (~%.2fkg/tuần).',
                $weightTrend['current_weight_kg'], $weightTrend['delta_kg'], $weightTrend['weekly_rate_kg']
            )
            : 'Xu hướng cân nặng: chưa có đủ lịch sử ghi cân để xác định.';

        // Target macro/nước theo chuẩn VDD 2016 — tính từ goal calo user chọn
        // để AI có số cụ thể tuân theo, không tự đoán tỉ lệ.
        $macroTargets = \App\Support\NutritionStandard::macroTargets($goal);
        $waterTarget  = \App\Support\NutritionStandard::waterTargetMl($weight);

        $ctx = [
            'gender'        => $gender,
            'age'           => $age,
            'height_cm'     => $height,
            'weight_kg'     => $weight,
            'calorie_goal'  => $goal,
            'bmr'           => (int) round($bmr),
            'tdee'          => $tdee,
            'target_protein_g' => $macroTargets['protein'],
            'target_carbs_g'   => $macroTargets['carbs'],
            'target_fat_g'     => $macroTargets['fat'],
            'target_water_ml'  => $waterTarget,
            'days_logged'   => $daysLogged,
            'avg_calories'  => $avgCalories,
            'avg_protein'   => $avgProtein,
            'avg_carbs'     => $avgCarbs,
            'avg_fat'       => $avgFat,
            'adherence'     => $adherence,
            'trend'         => $trend,
            'avg_water'     => $avgWater,
            'streak'        => (int) ($user->streak?->current_streak ?? 0),
            'weight_trend_line' => $weightTrendLine,
        ];

        // Ràng buộc món ăn theo sở thích/dị ứng + thói quen — nhồi vào prompt.
        $ctx['pref_constraints'] = $this->preferences->promptBlock($user)
            . "\n" . $this->preferences->habitPromptBlock($user);

        // preferences_hash vào data_hash → thêm dị ứng mới ⇒ plan cũ thành stale.
        $ctx['data_hash'] = sha1(implode('|', [
            $avgCalories, $adherence, $trend, $weight, $goal, $scope,
            $weightTrend['delta_kg'] ?? 0,
            $this->preferences->preferencesHash($user),
        ]));

        return $ctx;
    }

    /**
     * Gọi Gemini JSON mode để lấy kế hoạch có cấu trúc.
     *
     * @throws \RuntimeException
     * @return array<string,mixed>
     */
    public function getStructuredPlan(array $c, string $scope): array
    {
        $prompt = match ($scope) {
            'monthly' => $this->monthlyPrompt($c),
            'weekly'  => $this->weeklyPrompt($c),
            default   => $this->dailyPrompt($c),
        };

        // Kế hoạch tuần gồm 7 ngày → cần nhiều token hơn.
        $maxTokens = $scope === 'weekly' ? 6144 : 2048;

        try {
            $response = $this->http->post(
                "{$this->baseUrl}{$this->model}:generateContent?key={$this->apiKey}",
                [
                    'json' => [
                        // DEFENSE: system instruction kế hoạch — vai trò AI khi sinh meal plan JSON có schema
                        'systemInstruction' => [
                            'parts' => [['text' => 'Bạn là chuyên gia dinh dưỡng kiêm huấn luyện viên thể hình, am hiểu ẩm thực Việt Nam. CHỈ trả về JSON hợp lệ đúng schema, không giải thích thêm. Ưu tiên món Việt phổ biến, dễ mua/dễ nấu. KHÔNG chẩn đoán bệnh hay kê đơn thuốc — chỉ lập kế hoạch ăn uống/tập luyện thông thường.' . "\n\n" . \App\Support\NutritionStandard::promptStandardsBlock()]],
                        ],
                        'contents' => [
                            ['role' => 'user', 'parts' => [['text' => $prompt]]],
                        ],
                        'generationConfig' => [
                            'responseMimeType' => 'application/json',
                            'maxOutputTokens'  => $maxTokens,
                            'thinkingConfig'   => ['thinkingBudget' => 0],
                        ],
                    ],
                ]
            );
            $body = json_decode($response->getBody()->getContents(), true);
            $raw  = $body['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
            $plan = json_decode($raw, true) ?: [];
            $plan = $this->validatePlanSchema($plan, $scope, $raw, $body['candidates'][0]['finishReason'] ?? null);

            // Ngày bắt đầu tuần do server quyết định (không tin AI tính lịch).
            if ($scope === 'weekly') {
                $plan['week_start'] = now(config('app.timezone'))->startOfWeek()->toDateString();
            }

            return $plan;
        } catch (GuzzleException $e) {
            $this->logGeminiFailure('getStructuredPlan:' . $scope, $e);
            throw new \RuntimeException('Gemini API error: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Stream phần "vì sao kế hoạch này".
     *
     * @throws \RuntimeException
     */
    public function streamReasoning(array $c, array $plan, string $scope): \Generator
    {
        $scopeLabel = match ($scope) {
            'monthly' => 'tháng này',
            'weekly'  => 'tuần này',
            default   => 'ngày mai',
        };
        $summary    = $plan['summary'] ?? '';
        $prompt = <<<PROMPT
Kế hoạch {$scopeLabel} vừa lập có tóm tắt: "{$summary}".
Dữ liệu: calo TB {$c['avg_calories']} kcal/ngày (xu hướng {$c['trend']}), tuân thủ {$c['adherence']}%, mục tiêu {$c['calorie_goal']} kcal, streak {$c['streak']} ngày.

Viết 3–4 câu tiếng Việt giải thích:
1. Vì sao điều chỉnh theo hướng này (so với những gì họ đã ăn).
2. Điểm cần chú ý nhất.
3. Một động viên ngắn gắn với streak/độ tuân thủ.
Tự nhiên, thân thiện, không markdown heading, có thể dùng emoji.
PROMPT;

        try {
            $response = $this->http->post(
                "{$this->baseUrl}{$this->model}:streamGenerateContent?key={$this->apiKey}&alt=sse",
                [
                    'stream' => true,
                    'json'   => [
                        // DEFENSE: system instruction lý do kế hoạch — vai trò AI khi giải thích "vì sao plan này"
                        'systemInstruction' => [
                            'parts' => [['text' => 'Bạn là trợ lý dinh dưỡng thân thiện của app CaloEye. Trả lời tiếng Việt, ngắn gọn, tự nhiên. KHÔNG chẩn đoán bệnh hay kê đơn thuốc.']],
                        ],
                        'contents' => [
                            ['role' => 'user', 'parts' => [['text' => $prompt]]],
                        ],
                        'generationConfig' => [
                            'maxOutputTokens' => 1024,
                            'thinkingConfig'  => ['thinkingBudget' => 0],
                        ],
                    ],
                ]
            );
            $body   = $response->getBody();
            $buffer = '';
            while (!$body->eof()) {
                $buffer .= $body->read(512);
                $lines   = explode("\n", $buffer);
                $buffer  = array_pop($lines);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (!str_starts_with($line, 'data: ')) {
                        continue;
                    }
                    $chunk = json_decode(substr($line, 6), true);
                    $delta = $chunk['candidates'][0]['content']['parts'][0]['text'] ?? '';
                    if ($delta !== '') {
                        yield $delta;
                    }
                }
            }
        } catch (GuzzleException $e) {
            $this->logGeminiFailure('streamReasoning:' . $scope, $e);
            throw new \RuntimeException('Gemini streaming error: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Chuyển lời tư vấn trong hội thoại thành kế hoạch daily (schema giống dailyPrompt).
     * Grounding bằng context (số liệu + ràng buộc sở thích) để bám dữ liệu thật.
     *
     * @param  array<string,mixed>  $context  kết quả buildContext($user,'daily')
     * @param  array<int,array{role?:string,text?:string}>  $messages
     * @param  string  $dayLabel  mô tả ngày áp dụng bằng tiếng Việt (vd "HÔM NAY", "NGÀY MAI") — khớp
     *                            với target_date thực tế sẽ lưu, để AI không tự ý viết summary sai ngày.
     * @return array<string,mixed>
     * @throws \RuntimeException
     */
    public function planFromConversation(array $context, array $messages, string $dayLabel = 'HÔM NAY'): array
    {
        $advice = $this->extractAdvice($messages);

        $prompt = <<<PROMPT
Dưới đây là nội dung vừa trao đổi giữa người dùng và trợ lý dinh dưỡng:
\"\"\"
{$advice}
\"\"\"

{$context['pref_constraints']}
RÀNG BUỘC BẮT BUỘC: tuyệt đối KHÔNG món chứa nguyên liệu dị ứng; tuân thủ chế độ ăn nếu có.

Hãy chuyển lời tư vấn trên thành KẾ HOẠCH ĂN UỐNG & TẬP LUYỆN cho {$dayLabel}. Giữ đúng các món/bài tập đã được gợi ý trong hội thoại; nếu thiếu bữa nào thì bổ sung hợp lý theo mục tiêu {$context['calorie_goal']} kcal/ngày. Trả JSON đúng schema:
{"summary":"1 câu tóm tắt định hướng cho ngày này","target_calories":0,"target_macros":{"protein":0,"carbs":0,"fat":0},"water_target_ml":0,"meals":[{"slot":"breakfast|lunch|dinner|snack","name":"tên bữa/món","items":["món 1","món 2"],"calories":0,"protein":0,"carbs":0,"fat":0}],"workouts":[{"name":"tên bài tập","type":"cardio|strength|flexibility","duration_min":0,"intensity":"low|medium|high","est_calories_burned":0}],"tips":["lời khuyên ngắn 1","lời khuyên 2"]}

Tổng calo các bữa xấp xỉ mục tiêu (±10%).
PROMPT;

        try {
            $response = $this->http->post(
                "{$this->baseUrl}{$this->model}:generateContent?key={$this->apiKey}",
                [
                    'json' => [
                        'systemInstruction' => [
                            'parts' => [['text' => 'Bạn là chuyên gia dinh dưỡng kiêm huấn luyện viên thể hình, am hiểu ẩm thực Việt Nam. CHỈ trả về JSON hợp lệ đúng schema, không giải thích thêm. KHÔNG chẩn đoán bệnh hay kê đơn thuốc — chỉ lập kế hoạch ăn uống/tập luyện thông thường dựa trên lời tư vấn đã trao đổi.']],
                        ],
                        'contents'         => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                        'generationConfig' => [
                            'responseMimeType' => 'application/json',
                            'maxOutputTokens'  => 2048,
                            'thinkingConfig'   => ['thinkingBudget' => 0],
                        ],
                    ],
                ]
            );
            $body = json_decode($response->getBody()->getContents(), true);
            $raw  = $body['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
            $plan = json_decode($raw, true) ?: [];

            return $this->validatePlanSchema($plan, 'daily', $raw, $body['candidates'][0]['finishReason'] ?? null);
        } catch (GuzzleException $e) {
            $this->logGeminiFailure('planFromConversation', $e);
            throw new \RuntimeException('Gemini API error: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate JSON kế hoạch AI trả về đúng schema trước khi lưu vào meal_plans.plan — trước
     * đây chỉ có `getStructuredPlan()`/`planFromConversation()` decode JSON rồi dùng thẳng
     * (planFromConversation chỉ kiểm tra qua loa `meals` là array), nên field thiếu/sai kiểu
     * từ Gemini có thể lan ra tận FE hoặc lưu rác. Ném RuntimeException nếu sai — khớp hành vi
     * lỗi hiện có của 2 hàm gọi (catch(\Throwable) ở PlanController/ChatController).
     *
     * @throws \RuntimeException
     * @return array<string,mixed>
     */
    private function validatePlanSchema(array $plan, string $scope, string $raw, ?string $finishReason): array
    {
        $macroRules = [
            'target_macros'         => 'required|array',
            'target_macros.protein' => 'required|numeric|min:0|max:1000',
            'target_macros.carbs'   => 'required|numeric|min:0|max:2000',
            'target_macros.fat'     => 'required|numeric|min:0|max:1000',
        ];

        $mealRules = [
            'meals'            => 'required|array|min:1',
            'meals.*.slot'     => 'required|string|in:breakfast,lunch,dinner,snack',
            'meals.*.name'     => 'required|string|max:200',
            'meals.*.calories' => 'required|numeric|min:0|max:5000',
        ];

        $workoutRules = [
            'workouts'                 => 'present|array',
            'workouts.*.name'          => 'required_with:workouts.*|string|max:200',
            'workouts.*.type'          => 'required_with:workouts.*|string|in:cardio,strength,flexibility',
            'workouts.*.duration_min'  => 'required_with:workouts.*|numeric|min:0|max:300',
        ];

        $rules = match ($scope) {
            'weekly' => [
                'summary'                  => 'required|string|max:1000',
                'days'                     => 'required|array|size:7',
                'days.*.weekday'           => 'required|integer|between:1,7',
                'days.*.label'             => 'required|string|max:50',
                'days.*.target_calories'   => 'required|numeric|min:0|max:10000',
                'days.*.meals'             => 'required|array',
                'days.*.meals.*.slot'      => 'required|string|in:breakfast,lunch,dinner,snack',
                'days.*.meals.*.name'      => 'required|string|max:200',
                'days.*.meals.*.calories'  => 'required|numeric|min:0|max:5000',
                'tips'                     => 'present|array',
            ],
            'monthly' => [
                'summary'                  => 'required|string|max:1000',
                'avg_daily_calories'       => 'required|numeric|min:0|max:10000',
                'target_macros'            => 'required|array',
                'target_macros.protein'    => 'required|numeric|min:0',
                'target_macros.carbs'      => 'required|numeric|min:0',
                'target_macros.fat'        => 'required|numeric|min:0',
                'weekly_focus'             => 'present|array',
                'weekly_workout_split'     => 'present|array',
                'tips'                     => 'present|array',
            ],
            // daily — dùng chung cho getStructuredPlan(scope=daily) và planFromConversation()
            default => array_merge(
                [
                    'summary'          => 'required|string|max:500',
                    'target_calories'  => 'required|numeric|min:0|max:10000',
                    'water_target_ml'  => 'required|numeric|min:0|max:10000',
                    'tips'             => 'present|array',
                ],
                $macroRules,
                $mealRules,
                $workoutRules,
            ),
        };

        $validator = Validator::make($plan, $rules);

        if ($validator->fails()) {
            Log::warning('MealPlan: JSON kế hoạch AI không đúng schema', [
                'scope'         => $scope,
                'errors'        => $validator->errors()->toArray(),
                'finish_reason' => $finishReason,
                'raw'           => mb_substr($raw, 0, 1000),
            ]);
            throw new \RuntimeException('Kế hoạch AI trả về không đúng định dạng. Vui lòng thử lại.');
        }

        // validated() chỉ trả field có rule — giữ nguyên $plan gốc (đã qua validate) để không
        // mất field ngoài schema bắt buộc (vd items[] trong từng meal, không có rule riêng).
        return $plan;
    }

    /** Rút lời khuyên gần nhất của trợ lý + câu hỏi gần nhất của user để làm nguồn cho kế hoạch. */
    private function extractAdvice(array $messages): string
    {
        $lastAi = '';
        $lastUser = '';
        foreach (array_reverse($messages) as $m) {
            $role = $m['role'] ?? 'user';
            $text = trim((string) ($m['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            if ($lastAi === '' && in_array($role, ['ai', 'model'], true)) {
                $lastAi = mb_substr($text, 0, 1500);
            }
            if ($lastUser === '' && $role === 'user') {
                $lastUser = mb_substr($text, 0, 500);
            }
            if ($lastAi !== '' && $lastUser !== '') {
                break;
            }
        }

        return trim("Người dùng hỏi: {$lastUser}\nTrợ lý tư vấn: {$lastAi}");
    }

    private function dailyPrompt(array $c): string
    {
        $genderVi = $c['gender'] === 'male' ? 'Nam' : ($c['gender'] === 'female' ? 'Nữ' : 'Khác');
        $history  = $c['days_logged'] > 0
            ? "7 ngày qua: calo TB {$c['avg_calories']} kcal/ngày (xu hướng {$c['trend']}), macros TB protein {$c['avg_protein']}g/carbs {$c['avg_carbs']}g/fat {$c['avg_fat']}g, tuân thủ {$c['adherence']}%, nước TB {$c['avg_water']} ml/ngày."
            : 'Chưa có lịch sử ăn uống — lập kế hoạch chuẩn theo mục tiêu.';

        return <<<PROMPT
Hồ sơ: {$genderVi}, {$c['age']} tuổi, {$c['height_cm']}cm, {$c['weight_kg']}kg. BMR {$c['bmr']} kcal, TDEE {$c['tdee']} kcal, mục tiêu {$c['calorie_goal']} kcal/ngày.
Mục tiêu macro theo VDD 2016: đạm {$c['target_protein_g']}g, tinh bột {$c['target_carbs_g']}g, chất béo {$c['target_fat_g']}g. Nước: {$c['target_water_ml']} ml.
{$c['weight_trend_line']}
{$history}

{$c['pref_constraints']}
RÀNG BUỘC BẮT BUỘC: tuyệt đối KHÔNG đưa món chứa nguyên liệu người dùng dị ứng; tránh món họ không thích; tuân thủ chế độ ăn nếu có; ưu tiên xoay quanh món họ thích / hay ăn.

Lập kế hoạch ăn uống & tập luyện cho NGÀY MAI. Trả JSON đúng schema:
{"summary":"1 câu tóm tắt định hướng","target_calories":0,"target_macros":{"protein":0,"carbs":0,"fat":0},"water_target_ml":0,"meals":[{"slot":"breakfast|lunch|dinner|snack","name":"tên bữa/món","items":["món 1","món 2"],"calories":0,"protein":0,"carbs":0,"fat":0}],"workouts":[{"name":"tên bài tập","type":"cardio|strength|flexibility","duration_min":0,"intensity":"low|medium|high","est_calories_burned":0}],"tips":["lời khuyên ngắn 1","lời khuyên 2"]}

Tổng calo các bữa phải xấp xỉ mục tiêu (±5%).
PROMPT;
    }

    private function weeklyPrompt(array $c): string
    {
        $genderVi = $c['gender'] === 'male' ? 'Nam' : ($c['gender'] === 'female' ? 'Nữ' : 'Khác');
        $history  = $c['days_logged'] > 0
            ? "Tuần qua: calo TB {$c['avg_calories']} kcal/ngày (xu hướng {$c['trend']}), macros TB protein {$c['avg_protein']}g/carbs {$c['avg_carbs']}g/fat {$c['avg_fat']}g, tuân thủ {$c['adherence']}%, nước TB {$c['avg_water']} ml/ngày."
            : 'Chưa có lịch sử ăn uống — lập kế hoạch chuẩn theo mục tiêu.';

        // Nhãn 7 ngày (Thứ 2 → Chủ nhật) khớp weekday 1..7.
        $labels = 'weekday 1 = Thứ 2, 2 = Thứ 3, 3 = Thứ 4, 4 = Thứ 5, 5 = Thứ 6, 6 = Thứ 7, 7 = Chủ nhật';

        return <<<PROMPT
Hồ sơ: {$genderVi}, {$c['age']} tuổi, {$c['height_cm']}cm, {$c['weight_kg']}kg. BMR {$c['bmr']} kcal, TDEE {$c['tdee']} kcal, mục tiêu {$c['calorie_goal']} kcal/ngày.
Mục tiêu macro theo VDD 2016: đạm {$c['target_protein_g']}g, tinh bột {$c['target_carbs_g']}g, chất béo {$c['target_fat_g']}g. Nước: {$c['target_water_ml']} ml.
{$c['weight_trend_line']}
{$history}

{$c['pref_constraints']}
RÀNG BUỘC BẮT BUỘC: tuyệt đối KHÔNG đưa món chứa nguyên liệu người dùng dị ứng; tránh món họ không thích; tuân thủ chế độ ăn nếu có; ưu tiên xoay quanh món họ thích / hay ăn.

Lập kế hoạch ăn uống & tập luyện cho CẢ TUẦN (7 ngày, {$labels}). Mỗi ngày khác nhau, đa dạng món và xoay vòng nhóm cơ/loại bài tập hợp lý (có ngày nghỉ nhẹ). Trả JSON đúng schema:
{"summary":"1 câu định hướng cả tuần","days":[{"weekday":1,"label":"Thứ 2","focus":"trọng tâm ngày","target_calories":0,"target_macros":{"protein":0,"carbs":0,"fat":0},"water_target_ml":0,"meals":[{"slot":"breakfast|lunch|dinner|snack","name":"tên bữa/món","items":["món 1","món 2"],"calories":0,"protein":0,"carbs":0,"fat":0}],"workout":{"name":"tên bài tập","type":"cardio|strength|flexibility","duration_min":0,"intensity":"low|medium|high","est_calories_burned":0}}],"tips":["lời khuyên 1","lời khuyên 2"]}

days phải đủ đúng 7 phần tử, weekday từ 1 đến 7. Mỗi ngày tổng calo các bữa xấp xỉ mục tiêu (±5%). Ngày nghỉ đặt workout type "flexibility" cường độ "low".
PROMPT;
    }

    private function monthlyPrompt(array $c): string
    {
        $genderVi = $c['gender'] === 'male' ? 'Nam' : ($c['gender'] === 'female' ? 'Nữ' : 'Khác');
        return <<<PROMPT
Hồ sơ: {$genderVi}, {$c['age']} tuổi, {$c['height_cm']}cm, {$c['weight_kg']}kg. BMR {$c['bmr']} kcal, TDEE {$c['tdee']} kcal, mục tiêu {$c['calorie_goal']} kcal/ngày.
Mục tiêu macro theo VDD 2016: đạm {$c['target_protein_g']}g, tinh bột {$c['target_carbs_g']}g, chất béo {$c['target_fat_g']}g. Nước: {$c['target_water_ml']} ml.
{$c['weight_trend_line']}
30 ngày qua: calo TB {$c['avg_calories']} kcal/ngày (xu hướng {$c['trend']}), tuân thủ {$c['adherence']}%.

{$c['pref_constraints']}
RÀNG BUỘC BẮT BUỘC: tuyệt đối KHÔNG đưa món chứa nguyên liệu người dùng dị ứng; tránh món họ không thích; tuân thủ chế độ ăn nếu có; ưu tiên xoay quanh món họ thích / hay ăn.

Lập định hướng kế hoạch cho THÁNG NÀY. Trả JSON đúng schema:
{"summary":"1 câu định hướng tháng","avg_daily_calories":0,"target_macros":{"protein":0,"carbs":0,"fat":0},"expected_weight_change_kg":0,"weekly_focus":[{"week":1,"focus":"trọng tâm tuần","note":"ghi chú ngắn"}],"weekly_workout_split":[{"day":"Thứ 2","activity":"bài tập","duration_min":0}],"tips":["lời khuyên 1","lời khuyên 2"]}

weekly_focus đúng 4 tuần. weekly_workout_split 3-5 buổi/tuần.
PROMPT;
    }
}
