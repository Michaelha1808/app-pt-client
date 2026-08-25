<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\NutritionStandard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint public tính BMR/TDEE/target theo chuẩn VDD 2016 & WHO/FAO 2001.
 *
 * Register flow gọi endpoint này để gợi ý calorie_goal thay vì để user chọn
 * đại 1 trong 3 preset cứng (1500/2000/2500). Trả kèm citations để FE hiển
 * thị nguồn tham chiếu ngay dưới gợi ý.
 */
class NutritionController extends Controller
{
    /**
     * GET /api/v1/nutrition/standards
     * Metadata tĩnh cho FE (activity levels + citations) — không cần input.
     */
    public function standards(): JsonResponse
    {
        return response()->json([
            'activity_levels' => NutritionStandard::ACTIVITY_LABELS,
            'citations'       => NutritionStandard::citations(),
        ]);
    }

    /**
     * POST /api/v1/nutrition/calculate
     * Nhận profile + mức vận động + mục tiêu → trả BMR/TDEE/goal/macros/nước.
     */
    // DEFENSE: endpoint tính BMR/TDEE/target — public dùng ở Register step 3 auto-suggest calo goal
    public function calculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            // DEFENSE: input validate calculate — cùng khoảng với register (đồng bộ khi đổi)
            'birth_year'     => 'required|integer|between:1900,2015',
            'gender'         => 'required|in:male,female,other',
            'height_cm'      => 'required|numeric|between:50,300',
            'weight_kg'      => 'required|numeric|between:20,500',
            'activity_level' => 'required|in:sedentary,light,moderate,active,very_active',
            'goal'           => 'required|in:lose,maintain,gain',
        ]);

        $age    = (int) date('Y') - (int) $data['birth_year'];
        $bmr    = NutritionStandard::bmr(
            (float) $data['weight_kg'],
            (float) $data['height_cm'],
            $age,
            $data['gender'],
        );
        $tdee   = NutritionStandard::tdee($bmr, $data['activity_level']);
        $goalKcal = NutritionStandard::suggestCalorieGoal($tdee, $data['goal'], $data['gender']);
        $macros = NutritionStandard::macroTargets($goalKcal);
        $water  = NutritionStandard::waterTargetMl((float) $data['weight_kg']);

        return response()->json([
            'bmr'              => (int) round($bmr),
            'tdee'             => $tdee,
            'calorie_goal'     => $goalKcal,
            'target_macros'    => $macros,
            'water_target_ml'  => $water,
            'citations'        => NutritionStandard::citations(),
        ]);
    }
}
