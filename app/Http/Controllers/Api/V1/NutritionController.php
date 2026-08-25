<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Dish;
use App\Models\VddFood;
use App\Services\DishCompositionService;
use App\Support\NutritionStandard;
use App\Support\VietnameseText;
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

    /**
     * GET /api/v1/nutrition/lookup?q=<name>[&limit=10]
     * Tra cứu bảng Thành phần Thực phẩm VDD 2007/2017.
     * Search theo tên không dấu (LIKE %q%), sắp xếp: bắt đầu bằng q trước.
     */
    // DEFENSE: endpoint lookup FCT VDD — tra data 100g nguyên liệu chuẩn (không dùng cho suggest món)
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q'     => 'nullable|string|max:100',
            'code'  => 'nullable|string|max:20',
            'limit' => 'nullable|integer|between:1,50',
        ]);

        $query = VddFood::query();

        if (!empty($data['code'])) {
            $query->where('vdd_code', $data['code']);
        } elseif (!empty($data['q'])) {
            $norm = VietnameseText::normalize($data['q']);
            $query->where('name_normalized', 'like', "%{$norm}%")
                ->orderByRaw("CASE WHEN name_normalized LIKE ? THEN 0 ELSE 1 END", ["{$norm}%"])
                ->orderBy('name_vi');
        } else {
            return response()->json(['message' => 'Cần tham số q hoặc code'], 422);
        }

        $items = $query->limit($data['limit'] ?? 10)->get();

        return response()->json([
            'total' => $items->count(),
            'items' => $items->map(fn (VddFood $f) => [
                'vdd_code'      => $f->vdd_code,
                'name_vi'       => $f->name_vi,
                'name_en'       => $f->name_en,
                'group_name'    => $f->group_name,
                'per_100g'      => [
                    'energy_kcal'   => (float) $f->energy_kcal,
                    'protein_g'     => (float) $f->protein_g,
                    'fat_g'         => (float) $f->fat_g,
                    'carbs_g'       => (float) $f->carbs_g,
                    'fiber_g'       => (float) $f->fiber_g,
                    'calcium_mg'    => (float) $f->calcium_mg,
                    'iron_mg'       => (float) $f->iron_mg,
                    'sodium_mg'     => (float) $f->sodium_mg,
                    'potassium_mg'  => (float) $f->potassium_mg,
                    'zinc_mg'       => (float) $f->zinc_mg,
                    'vitamin_a_mcg' => (float) $f->vitamin_a_mcg,
                    'vitamin_c_mg'  => (float) $f->vitamin_c_mg,
                ],
            ]),
            'source' => [
                'title'  => 'Bảng Thành phần Thực phẩm Việt Nam',
                'author' => 'Viện Dinh dưỡng Quốc gia — Bộ Y tế',
                'year'   => 2007,
                'url'    => 'https://viendinhduong.vn/vi/cong-cu-va-tien-ich/gia-tri-dinh-duong',
            ],
        ]);
    }

    /**
     * GET /api/v1/nutrition/dish-composition/{dish}
     * Trả recipe + computed nutrition + so sánh với hardcode.
     * Public — user có thể xem "món này AI tính từ nguyên liệu nào theo FCT VDD".
     */
    // DEFENSE: endpoint dish composition — show recipe + tính lại từ FCT so với hardcode trong bảng dishes
    public function dishComposition(Dish $dish, DishCompositionService $composer): JsonResponse
    {
        $result = $composer->compareWithHardcoded($dish);

        return response()->json([
            'dish' => [
                'id'         => $dish->id,
                'name'       => $dish->name,
                'unit_label' => $dish->unit_label,
                'serving'    => $dish->serving,
            ],
            'has_recipe' => $result['computed'] !== null,
            'hardcoded'  => $result['hardcoded'],
            'computed'   => $result['computed'],
            'diff'       => $result['diff'],
            'ingredients' => $result['computed']
                ? ($composer->compute($dish)['ingredients'] ?? [])
                : [],
        ]);
    }
}
