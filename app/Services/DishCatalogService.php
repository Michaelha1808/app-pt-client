<?php

namespace App\Services;

use App\Models\Dish;
use Illuminate\Support\Collection;

/**
 * Grounding nhận diện món ăn vào thư viện chuẩn (nutrition DB).
 *
 * Gemini nhận diện TÊN món; service này khớp tên đó với 1 món trong bảng `dishes`
 * rồi thay calo/macro/tên bằng giá trị chuẩn từ DB. Món không khớp → giữ ước tính AI
 * và đánh dấu source='ai' (chính là ứng viên để bổ sung vào thư viện sau).
 */
class DishCatalogService
{
    /** Ngưỡng similar_text (%) để chấp nhận khớp mờ — đặt cao để tránh khớp sai. */
    // DEFENSE: ngưỡng fuzzy match — % khớp tối thiểu để dùng calo từ thư viện thay vì AI
    private const FUZZY_THRESHOLD = 88.0;

    private ?Collection $catalog = null;

    /**
     * Ground danh sách món đã nhận diện. Trả về mảng mới (không sửa input).
     *
     * @param  array<int,array<string,mixed>> $dishes
     * @return array<int,array<string,mixed>>
     */
    public function ground(array $dishes): array
    {
        return array_map(fn (array $d) => $this->groundOne($d), $dishes);
    }

    /**
     * Ground 1 món riêng lẻ — dùng cho flow `analyze` (chụp/mô tả 1 món)
     * và `estimate` (user sửa tên món). Tách khỏi ground() để cả 2 flow
     * đều dùng cùng logic khớp catalog thay vì để AI tự đoán số.
     *
     * @param  array<string,mixed> $d
     * @return array<string,mixed>
     */
    public function groundOne(array $d): array
    {
        $match = $this->match((string) ($d['food_name'] ?? ''));

        if (!$match) {
            $d['source']  = 'ai';
            $d['dish_id'] = null;
            return $d;
        }

        // Hệ số khẩu phần: nhân vào số chuẩn từ DB thay vì luôn trả đúng 1 khẩu phần cố định.
        // Ưu tiên gram thật (estimated_grams AI ước từ ảnh ÷ reference_grams đọc từ ảnh VDD qua
        // dishes:backfill-grams) — chính xác hơn vì so theo khối lượng tuyệt đối. Món chưa có
        // reference_grams (ảnh VDD không có bảng thành phần) → fallback portion_ratio (AI tự so
        // sánh tương đối với khẩu phần thường). Mặc định 1.0 nếu AI không ước tính được gì (vd
        // input chỉ có text).
        if ($match->reference_grams && !empty($d['estimated_grams'])) {
            $ratio = self::gramRatio((float) $d['estimated_grams'], (float) $match->reference_grams);
        } else {
            $ratio = max(0.3, min(3.0, (float) ($d['portion_ratio'] ?? 1.0)));
        }

        $d['food_name']  = $match->name;
        $d['unit_type']  = $match->unit_type;
        $d['unit_label'] = $match->unit_label;
        $d['serving']    = $match->serving . ($ratio < 0.85 || $ratio > 1.15 ? sprintf(' (~%.1fx khẩu phần chuẩn)', $ratio) : '');
        $d['calories']   = round($match->calories * $ratio, 1);
        $d['protein']    = (int) round($match->protein * $ratio);
        $d['carbs']      = (int) round($match->carbs   * $ratio);
        $d['fat']        = (int) round($match->fat     * $ratio);
        $d['sodium']     = (int) round($match->sodium  * $ratio);
        $d['source']     = 'catalog';
        $d['dish_id']    = $match->id;
        $d['portion_ratio'] = $ratio;
        // Có nguồn chuẩn → tin cậy cao
        $d['confidence'] = max((float) ($d['confidence'] ?? 0), 0.9);

        // portion luôn quy về 1 đơn vị chuẩn; countable giữ số lượng AI đếm
        if ($match->unit_type === 'portion') {
            $d['quantity_default'] = 1;
        }

        return $d;
    }

    /**
     * Scale số chuẩn của 1 dish theo gram NGƯỜI DÙNG tự nhập — dùng khi AI ước lượng khối
     * lượng từ ảnh sai và user sửa lại số gram thật ở màn xác nhận (trước khi lưu). Khác
     * groundOne() (ratio suy từ estimated_grams của AI): ở đây gram là số user tự chốt nên
     * tin cậy cao nhất, không cần confidence/fallback portion_ratio.
     *
     * @return array{serving:string,calories:float,protein:int,carbs:int,fat:int,sodium:int,source:string,dish_id:int}
     */
    public function scaleByGrams(Dish $dish, float $grams): array
    {
        $ratio = $dish->reference_grams
            ? self::gramRatio($grams, (float) $dish->reference_grams)
            : 1.0;

        return [
            'serving'  => $dish->serving . sprintf(' (~%dg)', (int) round($grams)),
            'calories' => round($dish->calories * $ratio, 1),
            'protein'  => (int) round($dish->protein * $ratio),
            'carbs'    => (int) round($dish->carbs * $ratio),
            'fat'      => (int) round($dish->fat * $ratio),
            'sodium'   => (int) round($dish->sodium * $ratio),
            'source'   => 'catalog',
            'dish_id'  => $dish->id,
        ];
    }

    /** Tỉ lệ khối lượng thật ÷ khối lượng chuẩn VDD, clamp để tránh số bất thường (vd gõ nhầm). */
    private static function gramRatio(float $grams, float $referenceGrams): float
    {
        return max(0.3, min(3.0, $grams / $referenceGrams));
    }

    /**
     * Khớp 1 tên món với thư viện: exact (đã chuẩn hoá) → alias → fuzzy.
     */
    public function match(string $name): ?Dish
    {
        $norm = self::normalize($name);
        if ($norm === '') {
            return null;
        }

        $best      = null;
        $bestScore = 0.0;

        foreach ($this->catalog() as $dish) {
            if ($dish->name_normalized === $norm) {
                return $dish; // khớp tuyệt đối
            }
            foreach (($dish->aliases ?? []) as $alias) {
                if (self::normalize((string) $alias) === $norm) {
                    return $dish;
                }
            }
            similar_text($norm, $dish->name_normalized, $pct);
            if ($pct > $bestScore) {
                $bestScore = $pct;
                $best      = $dish;
            }
        }

        return $bestScore >= self::FUZZY_THRESHOLD ? $best : null;
    }

    /**
     * Danh sách tên canonical trong thư viện — để gợi ý cho prompt (ưu tiên dùng đúng tên).
     *
     * @return array<int,string>
     */
    public function names(): array
    {
        return $this->catalog()->pluck('name')->all();
    }

    /** Tải thư viện 1 lần / request. */
    private function catalog(): Collection
    {
        return $this->catalog ??= Dish::all();
    }

    /**
     * Chuẩn hoá tên tiếng Việt: lowercase, bỏ dấu, đ→d, bỏ ký tự đặc biệt, gom khoảng trắng.
     */
    public static function normalize(string $s): string
    {
        return \App\Support\VietnameseText::normalize($s);
    }
}
