<?php

namespace App\Support;

class VietnameseText
{
    /**
     * Chuẩn hoá tên tiếng Việt: lowercase, bỏ dấu, đ→d, bỏ ký tự đặc biệt, gom khoảng trắng.
     * Dùng làm khoá so khớp/unique cho món ăn và sở thích người dùng.
     */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');

        $map = [
            'a' => ['à', 'á', 'ạ', 'ả', 'ã', 'â', 'ầ', 'ấ', 'ậ', 'ẩ', 'ẫ', 'ă', 'ằ', 'ắ', 'ặ', 'ẳ', 'ẵ'],
            'e' => ['è', 'é', 'ẹ', 'ẻ', 'ẽ', 'ê', 'ề', 'ế', 'ệ', 'ể', 'ễ'],
            'i' => ['ì', 'í', 'ị', 'ỉ', 'ĩ'],
            'o' => ['ò', 'ó', 'ọ', 'ỏ', 'õ', 'ô', 'ồ', 'ố', 'ộ', 'ổ', 'ỗ', 'ơ', 'ờ', 'ớ', 'ợ', 'ở', 'ỡ'],
            'u' => ['ù', 'ú', 'ụ', 'ủ', 'ũ', 'ư', 'ừ', 'ứ', 'ự', 'ử', 'ữ'],
            'y' => ['ỳ', 'ý', 'ỵ', 'ỷ', 'ỹ'],
            'd' => ['đ'],
        ];
        foreach ($map as $ascii => $chars) {
            $s = str_replace($chars, $ascii, $s);
        }

        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
