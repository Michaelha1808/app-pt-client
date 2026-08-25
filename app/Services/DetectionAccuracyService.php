<?php

namespace App\Services;

use App\Models\FoodDetectionSample;
use App\Models\VddFood;
use Illuminate\Support\Collection;

/**
 * So sánh calo/macro AI đoán với dữ liệu chuẩn VDD (catalog dishes hoặc FCT).
 *
 * Định nghĩa "đúng" (per món): AI's kcal nằm trong ±TOLERANCE_PCT so với
 * VDD-backed value. Nguồn "ground truth" ưu tiên:
 *   1) Dish catalog match (fuzzy) — catalog đã backed bởi FCT recipes
 *   2) VDD FCT lookup trực tiếp (cho nguyên liệu)
 *   3) Nếu không match → 'unmatched' (không đưa vào tính accuracy)
 *
 * Metric:
 *   - coverage    = matched / total           (bao nhiêu món match được VDD)
 *   - accuracy    = correct / matched         (bao nhiêu món trong "matched" đúng)
 *   - MAPE        = mean(|Δ| / vdd_kcal)     (% sai số trung bình, chỉ trên matched)
 */
class DetectionAccuracyService
{
    /** Ngưỡng ±% để coi là "đúng". Nghiên cứu dinh dưỡng thường chấp nhận ±20% do khẩu phần biến động. */
    public const TOLERANCE_PCT = 20.0;

    public function __construct(private DishCatalogService $catalog) {}

    /**
     * Chấm 1 sample: trả từng dish AI đoán + kết quả so VDD.
     *
     * @return array{
     *   sample_id:int, total:int, matched:int, correct:int,
     *   dishes:array<int,array<string,mixed>>
     * }
     */
    public function scoreSample(FoodDetectionSample $sample): array
    {
        $aiDishes = $sample->ai_dishes ?? [];
        $rows     = [];
        $matched  = 0;
        $correct  = 0;

        foreach ($aiDishes as $d) {
            $name    = (string) ($d['food_name'] ?? '');
            $aiKcal  = (int)    ($d['calories']  ?? 0);
            if ($name === '' || $aiKcal <= 0) continue;

            $result = $this->matchAgainstVdd($name, $aiKcal);
            $rows[] = array_merge(['ai_name' => $name, 'ai_kcal' => $aiKcal], $result);

            if ($result['source'] !== 'unmatched') {
                $matched++;
                if ($result['is_correct']) $correct++;
            }
        }

        return [
            'sample_id' => $sample->id,
            'total'     => count($rows),
            'matched'   => $matched,
            'correct'   => $correct,
            'dishes'    => $rows,
        ];
    }

    /**
     * Aggregate cho toàn bộ dataset (hoặc subset filter).
     *
     * @param  Collection<int,FoodDetectionSample> $samples
     * @return array{
     *   sample_count:int, total_dishes:int, matched:int, correct:int,
     *   coverage_pct:float, accuracy_pct:float, mape_pct:float,
     *   by_group:array<string,array<string,int|float>>
     * }
     */
    public function aggregate(Collection $samples): array
    {
        $totalDishes  = 0;
        $matched      = 0;
        $correct      = 0;
        $absErrorSum  = 0.0;   // sum of |ai_kcal - vdd_kcal| / vdd_kcal
        $byGroup      = [];    // group => ['matched'=>x,'correct'=>y,'mape_sum'=>z]

        foreach ($samples as $s) {
            $score = $this->scoreSample($s);
            $totalDishes += $score['total'];
            $matched     += $score['matched'];
            $correct     += $score['correct'];

            foreach ($score['dishes'] as $row) {
                if ($row['source'] === 'unmatched') continue;
                $absErrorSum += abs($row['error_pct']);

                $g = $row['group'] ?? 'Khác';
                $byGroup[$g] ??= ['matched' => 0, 'correct' => 0, 'mape_sum' => 0.0];
                $byGroup[$g]['matched']++;
                if ($row['is_correct']) $byGroup[$g]['correct']++;
                $byGroup[$g]['mape_sum'] += abs($row['error_pct']);
            }
        }

        $coveragePct = $totalDishes > 0 ? ($matched / $totalDishes) * 100 : 0;
        $accuracyPct = $matched > 0     ? ($correct / $matched) * 100     : 0;
        $mape        = $matched > 0     ? ($absErrorSum / $matched)       : 0;

        // Chuẩn hoá by_group thành số đọc được
        $groupStats = [];
        foreach ($byGroup as $g => $v) {
            $groupStats[$g] = [
                'matched'      => $v['matched'],
                'correct'      => $v['correct'],
                'accuracy_pct' => round($v['correct'] / $v['matched'] * 100, 1),
                'mape_pct'     => round($v['mape_sum'] / $v['matched'], 1),
            ];
        }

        return [
            'sample_count' => $samples->count(),
            'total_dishes' => $totalDishes,
            'matched'      => $matched,
            'correct'      => $correct,
            'coverage_pct' => round($coveragePct, 1),
            'accuracy_pct' => round($accuracyPct, 1),
            'mape_pct'     => round($mape, 1),
            'tolerance_pct' => self::TOLERANCE_PCT,
            'by_group'     => $groupStats,
        ];
    }

    /**
     * Match 1 tên món với VDD. Ưu tiên catalog `dishes` (đã backed bởi FCT recipes),
     * fallback sang FCT lookup trực tiếp cho nguyên liệu.
     *
     * @return array{
     *   source:string,           // 'catalog'|'fct'|'unmatched'
     *   vdd_name:?string,
     *   vdd_kcal:?int,           // reference kcal
     *   reference_unit:?string,  // '1 tô' hoặc '100g'
     *   error_pct:float,
     *   error_kcal:int,
     *   is_correct:bool,
     *   group:?string,
     * }
     */
    private function matchAgainstVdd(string $aiName, int $aiKcal): array
    {
        // Tier 1: catalog match (dish → hardcode kcal, đã calibrated với FCT nếu có recipe)
        $dish = $this->catalog->match($aiName);
        if ($dish) {
            $vddKcal   = $dish->calories;
            $errorPct  = $vddKcal > 0 ? (($aiKcal - $vddKcal) / $vddKcal) * 100 : 0;
            return [
                'source'         => 'catalog',
                'vdd_name'       => $dish->name,
                'vdd_kcal'       => $vddKcal,
                'reference_unit' => $dish->serving,
                'error_pct'      => round($errorPct, 1),
                'error_kcal'     => $aiKcal - $vddKcal,
                'is_correct'     => abs($errorPct) <= self::TOLERANCE_PCT,
                'group'          => 'Món ăn (catalog)',
            ];
        }

        // Tier 2: FCT trực tiếp — chỉ dùng khi tên món giống nguyên liệu (VD "Cơm trắng", "Trứng chiên")
        $norm = \App\Support\VietnameseText::normalize($aiName);
        $fct  = VddFood::where('name_normalized', 'like', "%{$norm}%")
            ->orderByRaw("CASE WHEN name_normalized LIKE ? THEN 0 ELSE 1 END", ["{$norm}%"])
            ->first();

        if ($fct) {
            // FCT là per-100g; AI thường ước tính per-serving. Vì khẩu phần khác nhau, dùng
            // heuristic: giả định serving ≈ 200g (chén cơm/tô canh/đĩa) → compare kcal/200g.
            // Đây là ước lượng "coarse", chấp nhận noise cao — chỉ dùng khi không match catalog.
            $vddKcal   = (int) round($fct->energy_kcal * 2.0); // 200g
            $errorPct  = $vddKcal > 0 ? (($aiKcal - $vddKcal) / $vddKcal) * 100 : 0;
            return [
                'source'         => 'fct',
                'vdd_name'       => $fct->name_vi,
                'vdd_kcal'       => $vddKcal,
                'reference_unit' => '~200g (estimate)',
                'error_pct'      => round($errorPct, 1),
                'error_kcal'     => $aiKcal - $vddKcal,
                'is_correct'     => abs($errorPct) <= self::TOLERANCE_PCT,
                'group'          => $fct->group_name,
            ];
        }

        return [
            'source'         => 'unmatched',
            'vdd_name'       => null,
            'vdd_kcal'       => null,
            'reference_unit' => null,
            'error_pct'      => 0.0,
            'error_kcal'     => 0,
            'is_correct'     => false,
            'group'          => null,
        ];
    }
}
