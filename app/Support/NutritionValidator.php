<?php

namespace App\Support;

/**
 * Kiểm tra cân bằng giữa calo tổng và macro theo hệ số Atwater
 * (protein 4 kcal/g, carbs 4 kcal/g, fat 9 kcal/g). Nếu lệch nhiều
 * → dữ liệu từ AI khả năng bịa số, cảnh báo user "chỉ tham khảo".
 */
class NutritionValidator
{
    /** Sai số cho phép: max(50 kcal, 20% của calories) — tránh cảnh báo với món kcal thấp. */
    public static function warning(int $calories, int $protein, int $carbs, int $fat): ?string
    {
        if ($calories <= 0) return null;
        // AI không ước tính được macro → không có gì để đối chiếu, bỏ qua.
        if ($protein === 0 && $carbs === 0 && $fat === 0) return null;

        $expected  = $protein * 4 + $carbs * 4 + $fat * 9;
        $diff      = abs($calories - $expected);
        $tolerance = max(50, (int) round($calories * 0.20));

        if ($diff <= $tolerance) return null;

        return 'Số calo và thành phần dinh dưỡng chưa cân đối — con số này chỉ mang tính tham khảo.';
    }
}
