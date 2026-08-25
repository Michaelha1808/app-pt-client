<?php

namespace App\Services;

use App\Models\Dish;

/**
 * Tính lại calo/macro/vi chất cho 1 khẩu phần món ăn từ recipe nguyên liệu FCT VDD.
 *
 * Công thức: sum(nguyên_liệu × grams / 100) cho từng chỉ số dinh dưỡng.
 * Chỉ có tác dụng cho món đã có recipe trong `dish_recipes`. Với món chưa có
 * recipe, trả về null → caller fallback sang giá trị hardcode trong `dishes`.
 */
class DishCompositionService
{
    /**
     * @return array{
     *   calories:int,protein:int,carbs:int,fat:int,fiber:int,sodium:int,
     *   calcium:int,iron:float,potassium:int,zinc:float,vitamin_a_mcg:int,vitamin_c_mg:int,
     *   ingredients:array<int,array<string,mixed>>,
     *   source:string
     * }|null
     */
    public function compute(Dish $dish): ?array
    {
        $dish->loadMissing('recipes.food');

        if ($dish->recipes->isEmpty()) {
            return null;
        }

        $totals = [
            'calories' => 0.0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0, 'fiber' => 0.0,
            'sodium'   => 0.0, 'calcium' => 0.0, 'iron' => 0.0, 'potassium' => 0.0, 'zinc' => 0.0,
            'vitamin_a_mcg' => 0.0, 'vitamin_c_mg' => 0.0,
        ];
        $ingredients = [];

        foreach ($dish->recipes as $r) {
            $factor = $r->grams_per_serving / 100.0;
            $f      = $r->food;

            $totals['calories']       += $f->energy_kcal * $factor;
            $totals['protein']        += $f->protein_g   * $factor;
            $totals['carbs']          += $f->carbs_g     * $factor;
            $totals['fat']            += $f->fat_g       * $factor;
            $totals['fiber']          += $f->fiber_g     * $factor;
            $totals['sodium']         += $f->sodium_mg   * $factor;
            $totals['calcium']        += $f->calcium_mg  * $factor;
            $totals['iron']           += $f->iron_mg     * $factor;
            $totals['potassium']      += $f->potassium_mg * $factor;
            $totals['zinc']           += $f->zinc_mg     * $factor;
            $totals['vitamin_a_mcg']  += $f->vitamin_a_mcg * $factor;
            $totals['vitamin_c_mg']   += $f->vitamin_c_mg  * $factor;

            $ingredients[] = [
                'name'     => $f->name_vi,
                'vdd_code' => $f->vdd_code,
                'grams'    => (float) $r->grams_per_serving,
                'note'     => $r->note,
            ];
        }

        return [
            'calories'      => (int) round($totals['calories']),
            'protein'       => (int) round($totals['protein']),
            'carbs'         => (int) round($totals['carbs']),
            'fat'           => (int) round($totals['fat']),
            'fiber'         => (int) round($totals['fiber']),
            'sodium'        => (int) round($totals['sodium']),
            'calcium'       => (int) round($totals['calcium']),
            'iron'          => round($totals['iron'], 1),
            'potassium'     => (int) round($totals['potassium']),
            'zinc'          => round($totals['zinc'], 1),
            'vitamin_a_mcg' => (int) round($totals['vitamin_a_mcg']),
            'vitamin_c_mg'  => (int) round($totals['vitamin_c_mg']),
            'ingredients'   => $ingredients,
            'source'        => 'FCT VDD 2007/2017',
        ];
    }

    /**
     * So sánh giá trị hardcode trong `dishes` với computed từ FCT.
     * Giúp Admin/dev verify hardcode có sát VDD hay không.
     *
     * @return array{hardcoded:array<string,int>,computed:array<string,int>|null,diff:array<string,int>|null}
     */
    public function compareWithHardcoded(Dish $dish): array
    {
        $computed = $this->compute($dish);
        $hardcoded = [
            'calories' => $dish->calories,
            'protein'  => $dish->protein,
            'carbs'    => $dish->carbs,
            'fat'      => $dish->fat,
            'sodium'   => $dish->sodium,
        ];

        if (!$computed) {
            return ['hardcoded' => $hardcoded, 'computed' => null, 'diff' => null];
        }

        $diff = [];
        foreach ($hardcoded as $k => $v) {
            $diff[$k] = $computed[$k] - $v;
        }

        return [
            'hardcoded' => $hardcoded,
            'computed'  => array_intersect_key($computed, $hardcoded),
            'diff'      => $diff,
        ];
    }
}
