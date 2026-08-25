<?php

namespace Database\Seeders;

use App\Models\Dish;
use App\Models\DishIngredient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Nạp `dishes.reference_grams` + `dish_ingredients` đã backfill sẵn (local, qua
 * `dishes:backfill-grams` — đọc ảnh VDD bằng Gemini Vision, xem DishesBackfillGrams).
 *
 * Tách khỏi command gốc để môi trường khác (VPS...) KHÔNG phải gọi lại Gemini cho
 * ~1171 ảnh (tốn thời gian/API) — chỉ cần import JSON đã có sẵn kết quả.
 * Khớp theo tên món (Dish::name) — bỏ qua món không tìm thấy hoặc đã có reference_grams.
 */
class DishGramsSeeder extends Seeder
{
    public function run(): void
    {
        $path = __DIR__.'/data/dish_grams.json';
        $rows = json_decode(file_get_contents($path), true);

        $updated = $skippedNoMatch = $skippedAlready = 0;

        foreach ($rows as $r) {
            $dish = Dish::where('name', $r['name'])->whereNull('reference_grams')->first();
            if (!$dish) {
                if (Dish::where('name', $r['name'])->exists()) {
                    $skippedAlready++;
                } else {
                    $skippedNoMatch++;
                }
                continue;
            }

            DB::transaction(function () use ($dish, $r) {
                $dish->update(['reference_grams' => $r['reference_grams']]);
                foreach ($r['ingredients'] as $ing) {
                    DishIngredient::create([
                        'dish_id' => $dish->id,
                        'name'    => mb_substr($ing['name'], 0, 150),
                        'grams'   => $ing['grams'],
                        'kcal'    => $ing['kcal'],
                        'order'   => $ing['order'],
                    ]);
                }
            });
            $updated++;
        }

        $this->command?->info("DishGramsSeeder: cập nhật {$updated} món / ".count($rows)." nguồn ({$skippedAlready} đã có sẵn, {$skippedNoMatch} không khớp tên).");
    }
}
