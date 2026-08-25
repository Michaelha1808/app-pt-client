<?php

namespace App\Support;

/**
 * Chuẩn dinh dưỡng và công thức tham chiếu — nguồn được ghi rõ trong citations().
 *
 * Đặt ở một chỗ duy nhất để:
 *  - Backend (services, controller) và prompt AI đều dùng chung con số,
 *  - Frontend hiển thị được nguồn khi tư vấn (user tin cậy).
 *
 * Nguồn chính:
 *  - Bảng Nhu cầu Dinh dưỡng Khuyến nghị cho người Việt Nam (Viện Dinh dưỡng
 *    Quốc gia — Bộ Y tế Việt Nam, 2016).
 *  - Human Energy Requirements — FAO/WHO/UNU Expert Consultation, 2001.
 *  - Mifflin MD, St Jeor ST et al., 1990 (BMR formula).
 */
class NutritionStandard
{
    /** PAL — Physical Activity Level (WHO/FAO 2001, VDD adopt) */
    public const PAL = [
        'sedentary'   => 1.2,
        'light'       => 1.375,
        'moderate'    => 1.55,
        'active'      => 1.725,
        'very_active' => 1.9,
    ];

    /** Nhãn tiếng Việt cho FE picker */
    public const ACTIVITY_LABELS = [
        'sedentary'   => ['label' => 'Ít vận động',      'desc' => 'Chủ yếu ngồi, không tập thể thao'],
        'light'       => ['label' => 'Vận động nhẹ',    'desc' => 'Tập thể thao 1–3 buổi/tuần'],
        'moderate'    => ['label' => 'Vận động vừa',    'desc' => 'Tập thể thao 3–5 buổi/tuần'],
        'active'      => ['label' => 'Vận động nhiều',  'desc' => 'Tập thể thao 6–7 buổi/tuần'],
        'very_active' => ['label' => 'Vận động rất nhiều', 'desc' => 'Vận động viên hoặc lao động nặng'],
    ];

    /** Điều chỉnh calo theo mục tiêu (an toàn theo VDD & WHO) */
    public const GOAL_ADJUSTMENT = [
        'lose'     => -500,  // ~0.5 kg/tuần
        'maintain' => 0,
        'gain'     => 300,   // ~0.25 kg/tuần
    ];

    /** Sàn calo/ngày để không thiếu vi chất (VDD 2016) */
    public const MIN_CALORIES = [
        'male'   => 1500,
        'female' => 1200,
        'other'  => 1200,
    ];

    /** Tỉ lệ macro khuyến nghị (%) — VDD 2016 (AMDR trung tâm khoảng cho phép) */
    public const MACRO_RATIO = [
        'protein_pct' => 0.15,   // 13–20%
        'carbs_pct'   => 0.55,   // 50–65%
        'fat_pct'     => 0.30,   // 20–30%
    ];

    /** Nước: ml × kg cân nặng (VDD) */
    public const WATER_ML_PER_KG = 35;

    public static function pal(?string $activityLevel): float
    {
        return self::PAL[$activityLevel] ?? self::PAL['light'];
    }

    /** BMR — Mifflin-St Jeor 1990 (VDD 2016 công nhận) */
    public static function bmr(float $weightKg, float $heightCm, int $age, string $gender): float
    {
        return 10 * $weightKg + 6.25 * $heightCm - 5 * $age + ($gender === 'male' ? 5 : -161);
    }

    public static function tdee(float $bmr, ?string $activityLevel): int
    {
        return (int) round($bmr * self::pal($activityLevel));
    }

    public static function suggestCalorieGoal(int $tdee, string $goal, string $gender): int
    {
        $adjusted = $tdee + (self::GOAL_ADJUSTMENT[$goal] ?? 0);
        $floor    = self::MIN_CALORIES[$gender] ?? self::MIN_CALORIES['other'];
        return max($floor, $adjusted);
    }

    /**
     * Tính lượng gram macro/ngày cho một mục tiêu calo.
     *
     * @return array{protein:int,carbs:int,fat:int}
     */
    public static function macroTargets(int $calories): array
    {
        return [
            'protein' => (int) round($calories * self::MACRO_RATIO['protein_pct'] / 4),
            'carbs'   => (int) round($calories * self::MACRO_RATIO['carbs_pct']   / 4),
            'fat'     => (int) round($calories * self::MACRO_RATIO['fat_pct']     / 9),
        ];
    }

    public static function waterTargetMl(float $weightKg): int
    {
        return (int) round($weightKg * self::WATER_ML_PER_KG);
    }

    /**
     * Nguồn trích dẫn — trả cho FE để hiển thị dưới mỗi tư vấn.
     *
     * @return array<int,array{id:string,title:string,author:string,year:int,url:string}>
     */
    public static function citations(): array
    {
        return [
            [
                'id'     => 'vdd-2016',
                'title'  => 'Bảng Nhu cầu Dinh dưỡng Khuyến nghị cho người Việt Nam',
                'author' => 'Viện Dinh dưỡng Quốc gia — Bộ Y tế',
                'year'   => 2016,
                'url'    => 'https://viendinhduong.vn/vi/nhu-cau-dinh-duong-khuyen-nghi.html',
            ],
            [
                'id'     => 'who-fao-2001',
                'title'  => 'Human Energy Requirements — FAO/WHO/UNU Expert Consultation',
                'author' => 'WHO / FAO / UNU',
                'year'   => 2001,
                'url'    => 'https://www.fao.org/3/y5686e/y5686e00.htm',
            ],
            [
                'id'     => 'mifflin-1990',
                'title'  => 'A new predictive equation for resting energy expenditure in healthy individuals',
                'author' => 'Mifflin MD, St Jeor ST et al.',
                'year'   => 1990,
                'url'    => 'https://pubmed.ncbi.nlm.nih.gov/2305711/',
            ],
        ];
    }

    /**
     * Đoạn text nhồi vào system instruction để AI lập kế hoạch bám chuẩn VDD.
     * Không giấu nguồn — AI có thể tham chiếu tự nhiên trong tips.
     */
    public static function promptStandardsBlock(): string
    {
        return "CHUẨN THAM CHIẾU — bám sát khi lập kế hoạch:\n"
            . "- Tỉ lệ năng lượng (VDD 2016): protein 13–20%, carbs 50–65%, fat 20–30%.\n"
            . "- Natri < 2000 mg/ngày (WHO); nếu buộc phải cao hơn thì cảnh báo.\n"
            . "- Chất xơ 20–25 g/ngày; đường tự do < 10% năng lượng.\n"
            . "- Nước 35 ml × kg cân nặng.\n"
            . "- Ưu tiên nguyên liệu Việt Nam, phổ biến, dễ mua.\n";
    }
}
