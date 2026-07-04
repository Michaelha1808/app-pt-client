<?php

namespace App\Services;

use App\Models\FoodDetectionSample;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Thu thập dữ liệu nhận diện món ăn (AI đoán + user sửa) để cải thiện model về sau:
 * few-shot động, sửa nutrition DB, hoặc fine-tune Vertex AI.
 *
 * Toàn bộ thao tác best-effort: lỗi ở đây KHÔNG được làm hỏng luồng nhận diện chính.
 */
class FoodSampleService
{
    private const DISK = 'local';            // private: storage/app/private
    private const MAX_EDGE = 512;            // downscale cạnh dài tối đa (px) khi có GD

    /**
     * Lưu 1 mẫu ngay khi `detect` chạy xong. Trả về sample (hoặc null nếu lỗi).
     *
     * @param  array<int,array<string,mixed>> $dishes  AI đoán (đã normalize)
     */
    public function capture(?string $image, ?string $text, array $dishes, ?int $userId, string $model): ?FoodDetectionSample
    {
        try {
            $imagePath = $image ? $this->storeImage($image) : null;

            return FoodDetectionSample::create([
                'user_id'    => $userId,
                'input_type' => $image ? 'image' : 'text',
                'image_path' => $imagePath,
                'text_input' => $text,
                'model'      => $model,
                'ai_dishes'  => $dishes,
            ]);
        } catch (\Throwable $e) {
            Log::warning('FoodSampleService.capture failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Ghi nhận kết quả user chốt (sau khi sửa). Tính has_correction bằng cách so với ai_dishes.
     *
     * @param  array<int,array{food_name?:string,calories?:int|float,quantity?:int|float,selected?:bool}> $corrected
     */
    public function recordFeedback(FoodDetectionSample $sample, array $corrected, bool $saved): void
    {
        try {
            if ($sample->corrected_dishes !== null) {
                return; // đã chốt rồi, không ghi đè (tránh spam)
            }

            $sample->update([
                'corrected_dishes' => $corrected,
                'has_correction'   => $this->hasCorrection($sample->ai_dishes ?? [], $corrected),
                'saved'            => $saved,
            ]);
        } catch (\Throwable $e) {
            Log::warning('FoodSampleService.recordFeedback failed: ' . $e->getMessage());
        }
    }

    /**
     * Rút các cặp "AI đoán sai tên → user sửa đúng" từ dataset để làm few-shot cho prompt detect.
     * Chỉ lấy mẫu có sửa tên thật sự. Trả về distinct, mới nhất trước.
     *
     * @return array<int,array{from:string,to:string}>
     */
    public function nameCorrectionExamples(int $limit = 8): array
    {
        try {
            $samples = FoodDetectionSample::query()
                ->where('has_correction', true)
                ->whereNotNull('corrected_dishes')
                ->latest('id')
                ->limit(50)
                ->get(['ai_dishes', 'corrected_dishes']);

            $pairs = [];
            foreach ($samples as $s) {
                $ai        = $s->ai_dishes ?? [];
                $corrected = $s->corrected_dishes ?? [];
                foreach ($corrected as $i => $c) {
                    $from = trim((string) ($ai[$i]['food_name'] ?? ''));
                    $to   = trim((string) ($c['food_name'] ?? ''));
                    if ($from === '' || $to === '' || mb_strtolower($from) === mb_strtolower($to)) {
                        continue;
                    }
                    $key = mb_strtolower($from) . '|' . mb_strtolower($to);
                    $pairs[$key] ??= ['from' => $from, 'to' => $to];
                    if (count($pairs) >= $limit) {
                        break 2;
                    }
                }
            }

            return array_values($pairs);
        } catch (\Throwable $e) {
            Log::warning('FoodSampleService.nameCorrectionExamples failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * So sánh AI đoán vs user chốt: coi là "có sửa" nếu user bỏ chọn, đổi tên, đổi calo, hoặc đổi số lượng.
     *
     * @param  array<int,array<string,mixed>> $ai
     * @param  array<int,array<string,mixed>> $corrected
     */
    private function hasCorrection(array $ai, array $corrected): bool
    {
        foreach ($corrected as $i => $c) {
            $orig = $ai[$i] ?? null;
            if ($orig === null) {
                return true;
            }
            if (($c['selected'] ?? true) === false) {
                return true; // AI nhận nhầm món / user không ăn
            }
            $aiName   = mb_strtolower(trim((string) ($orig['food_name'] ?? '')));
            $userName = mb_strtolower(trim((string) ($c['food_name'] ?? '')));
            if ($userName !== '' && $userName !== $aiName) {
                return true; // sửa tên
            }
            if ((int) ($c['calories'] ?? 0) !== (int) ($orig['calories'] ?? 0)) {
                return true; // sửa calo
            }
            if ((float) ($c['quantity'] ?? 1) !== (float) ($orig['quantity_default'] ?? 1)) {
                return true; // chỉnh số lượng / khẩu phần
            }
        }

        return false;
    }

    /**
     * Lưu ảnh data-URI vào disk private. Downscale 512px nếu có GD, không thì lưu nguyên.
     */
    private function storeImage(string $dataUri): ?string
    {
        if (!preg_match('/^data:([^;]+);base64,(.+)$/', $dataUri, $m)) {
            return null;
        }

        $binary = base64_decode($m[2], true);
        if ($binary === false) {
            return null;
        }

        if (extension_loaded('gd')) {
            $binary = $this->downscale($binary) ?? $binary;
        }

        $path = 'food-samples/' . date('Y/m') . '/' . Str::uuid() . '.jpg';
        Storage::disk(self::DISK)->put($path, $binary);

        return $path;
    }

    /**
     * Downscale ảnh về cạnh dài <= MAX_EDGE, re-encode JPEG q80. Null nếu không cần / lỗi.
     */
    private function downscale(string $binary): ?string
    {
        $src = @imagecreatefromstring($binary);
        if (!$src) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = self::MAX_EDGE / max($w, $h);

        if ($scale >= 1) {
            imagedestroy($src);
            return null; // đã đủ nhỏ
        }

        $nw  = (int) round($w * $scale);
        $nh  = (int) round($h * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagejpeg($dst, null, 80);
        $out = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        return $out ?: null;
    }
}
