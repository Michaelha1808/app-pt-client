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
        return array_map(function (array $d) {
            $match = $this->match((string) ($d['food_name'] ?? ''));

            if (!$match) {
                $d['source']  = 'ai';
                $d['dish_id'] = null;
                return $d;
            }

            $d['food_name']  = $match->name;
            $d['unit_type']  = $match->unit_type;
            $d['unit_label'] = $match->unit_label;
            $d['serving']    = $match->serving;
            $d['calories']   = $match->calories;
            $d['protein']    = $match->protein;
            $d['carbs']      = $match->carbs;
            $d['fat']        = $match->fat;
            $d['sodium']     = $match->sodium;
            $d['source']     = 'catalog';
            $d['dish_id']    = $match->id;
            // Có nguồn chuẩn → tin cậy cao
            $d['confidence'] = max((float) ($d['confidence'] ?? 0), 0.9);

            // portion luôn quy về 1 đơn vị chuẩn; countable giữ số lượng AI đếm
            if ($match->unit_type === 'portion') {
                $d['quantity_default'] = 1;
            }

            return $d;
        }, $dishes);
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
        $s = mb_strtolower(trim($s), 'UTF-8');

        $map = [
            'a' => ['à','á','ạ','ả','ã','â','ầ','ấ','ậ','ẩ','ẫ','ă','ằ','ắ','ặ','ẳ','ẵ'],
            'e' => ['è','é','ẹ','ẻ','ẽ','ê','ề','ế','ệ','ể','ễ'],
            'i' => ['ì','í','ị','ỉ','ĩ'],
            'o' => ['ò','ó','ọ','ỏ','õ','ô','ồ','ố','ộ','ổ','ỗ','ơ','ờ','ớ','ợ','ở','ỡ'],
            'u' => ['ù','ú','ụ','ủ','ũ','ư','ừ','ứ','ự','ử','ữ'],
            'y' => ['ỳ','ý','ỵ','ỷ','ỹ'],
            'd' => ['đ'],
        ];
        foreach ($map as $ascii => $chars) {
            $s = str_replace($chars, $ascii, $s);
        }

        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
