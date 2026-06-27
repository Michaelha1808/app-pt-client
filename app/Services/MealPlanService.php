<?php

namespace App\Services;

use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class MealPlanService
{
    private Client $http;
    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.key');
        $this->model  = config('services.gemini.model', 'gemini-2.0-flash');
        $this->http   = new Client(['timeout' => 45]);
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
        $bmr    = 10 * $weight + 6.25 * $height - 5 * $age + ($gender === 'male' ? 5 : -161);
        $tdee   = (int) round($bmr * 1.375);

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

        $ctx = [
            'gender'        => $gender,
            'age'           => $age,
            'height_cm'     => $height,
            'weight_kg'     => $weight,
            'calorie_goal'  => $goal,
            'bmr'           => (int) round($bmr),
            'tdee'          => $tdee,
            'days_logged'   => $daysLogged,
            'avg_calories'  => $avgCalories,
            'avg_protein'   => $avgProtein,
            'avg_carbs'     => $avgCarbs,
            'avg_fat'       => $avgFat,
            'adherence'     => $adherence,
            'trend'         => $trend,
            'avg_water'     => $avgWater,
            'streak'        => (int) ($user->streak?->current_streak ?? 0),
        ];

        $ctx['data_hash'] = sha1(implode('|', [
            $avgCalories, $adherence, $trend, $weight, $goal, $scope,
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
        $prompt = $scope === 'monthly'
            ? $this->monthlyPrompt($c)
            : $this->dailyPrompt($c);

        try {
            $response = $this->http->post(
                "{$this->baseUrl}{$this->model}:generateContent?key={$this->apiKey}",
                [
                    'json' => [
                        'systemInstruction' => [
                            'parts' => [['text' => 'Bạn là chuyên gia dinh dưỡng kiêm huấn luyện viên thể hình, am hiểu ẩm thực Việt Nam. CHỈ trả về JSON hợp lệ đúng schema, không giải thích thêm. Ưu tiên món Việt phổ biến, dễ mua/dễ nấu.']],
                        ],
                        'contents' => [
                            ['role' => 'user', 'parts' => [['text' => $prompt]]],
                        ],
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
            return json_decode($raw, true) ?: [];
        } catch (GuzzleException $e) {
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
        $scopeLabel = $scope === 'monthly' ? 'tháng này' : 'ngày mai';
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
                        'systemInstruction' => [
                            'parts' => [['text' => 'Bạn là trợ lý dinh dưỡng thân thiện của app CaloEye. Trả lời tiếng Việt, ngắn gọn, tự nhiên.']],
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
            throw new \RuntimeException('Gemini streaming error: ' . $e->getMessage(), 0, $e);
        }
    }

    private function dailyPrompt(array $c): string
    {
        $genderVi = $c['gender'] === 'male' ? 'Nam' : ($c['gender'] === 'female' ? 'Nữ' : 'Khác');
        $history  = $c['days_logged'] > 0
            ? "7 ngày qua: calo TB {$c['avg_calories']} kcal/ngày (xu hướng {$c['trend']}), macros TB protein {$c['avg_protein']}g/carbs {$c['avg_carbs']}g/fat {$c['avg_fat']}g, tuân thủ {$c['adherence']}%, nước TB {$c['avg_water']} ml/ngày."
            : 'Chưa có lịch sử ăn uống — lập kế hoạch chuẩn theo mục tiêu.';

        return <<<PROMPT
Hồ sơ: {$genderVi}, {$c['age']} tuổi, {$c['height_cm']}cm, {$c['weight_kg']}kg. BMR {$c['bmr']} kcal, TDEE {$c['tdee']} kcal, mục tiêu {$c['calorie_goal']} kcal/ngày.
{$history}

Lập kế hoạch ăn uống & tập luyện cho NGÀY MAI. Trả JSON đúng schema:
{"summary":"1 câu tóm tắt định hướng","target_calories":0,"target_macros":{"protein":0,"carbs":0,"fat":0},"water_target_ml":0,"meals":[{"slot":"breakfast|lunch|dinner|snack","name":"tên bữa/món","items":["món 1","món 2"],"calories":0,"protein":0,"carbs":0,"fat":0}],"workouts":[{"name":"tên bài tập","type":"cardio|strength|flexibility","duration_min":0,"intensity":"low|medium|high","est_calories_burned":0}],"tips":["lời khuyên ngắn 1","lời khuyên 2"]}

Tổng calo các bữa phải xấp xỉ mục tiêu (±5%).
PROMPT;
    }

    private function monthlyPrompt(array $c): string
    {
        $genderVi = $c['gender'] === 'male' ? 'Nam' : ($c['gender'] === 'female' ? 'Nữ' : 'Khác');
        return <<<PROMPT
Hồ sơ: {$genderVi}, {$c['age']} tuổi, {$c['height_cm']}cm, {$c['weight_kg']}kg. BMR {$c['bmr']} kcal, TDEE {$c['tdee']} kcal, mục tiêu {$c['calorie_goal']} kcal/ngày.
30 ngày qua: calo TB {$c['avg_calories']} kcal/ngày (xu hướng {$c['trend']}), tuân thủ {$c['adherence']}%.

Lập định hướng kế hoạch cho THÁNG NÀY. Trả JSON đúng schema:
{"summary":"1 câu định hướng tháng","avg_daily_calories":0,"target_macros":{"protein":0,"carbs":0,"fat":0},"expected_weight_change_kg":0,"weekly_focus":[{"week":1,"focus":"trọng tâm tuần","note":"ghi chú ngắn"}],"weekly_workout_split":[{"day":"Thứ 2","activity":"bài tập","duration_min":0}],"tips":["lời khuyên 1","lời khuyên 2"]}

weekly_focus đúng 4 tuần. weekly_workout_split 3-5 buổi/tuần.
PROMPT;
    }
}
