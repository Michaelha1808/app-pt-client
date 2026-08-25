<?php

namespace App\Console\Commands;

use App\Models\Dish;
use App\Models\DishIngredient;
use App\Services\FoodAnalysisService;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill 1 lần: đọc bảng "Thành phần" (nguyên liệu + gram + kcal) từ ảnh minh
 * hoạ món ăn của VDD (qua Gemini Vision) để có `dishes.reference_grams` thật —
 * mẫu số dùng quy đổi khối lượng AI ước tính từ ảnh user chụp sang tỉ lệ khẩu
 * phần (xem DishCatalogService::groundOne()).
 *
 * Không phải mọi món đều có bảng thành phần trong ảnh (~30% chỉ có ảnh + kcal
 * tổng) — các món đó reference_grams giữ null, groundOne() tự fallback sang
 * portion_ratio (AI ước tỉ lệ tương đối) như trước.
 */
class DishesBackfillGrams extends Command
{
    protected $signature = 'dishes:backfill-grams {--limit=} {--dry-run} {--sleep=350}';

    protected $description = 'Đọc reference_grams + dish_ingredients từ ảnh VDD qua Gemini Vision (chạy 1 lần)';

    public function handle(FoodAnalysisService $ai): int
    {
        $fixturePath = database_path('seeders/data/vdd_dishes.json');
        $fixture     = json_decode(file_get_contents($fixturePath), true);
        $imageByName = collect($fixture)->keyBy('name');

        $query = Dish::whereNull('reference_grams');
        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }
        $dishes = $query->get();

        $this->info("Sẽ xử lý {$dishes->count()} món (đã có reference_grams sẽ bỏ qua).");

        $http     = new Client(['timeout' => 20]);
        $sleepMs  = (int) $this->option('sleep');
        $dryRun   = (bool) $this->option('dry-run');
        $ok = $noImage = $noData = $failed = 0;

        $bar = $this->output->createProgressBar($dishes->count());
        $bar->start();

        foreach ($dishes as $dish) {
            $bar->advance();

            $entry = $imageByName[$dish->name] ?? null;
            $image = $entry['image'] ?? null;
            if (!$image) {
                $noImage++;
                continue;
            }

            try {
                $res = $http->get('https://viendinhduong.vn' . $image);
                $bytes = $res->getBody()->getContents();
                $mime  = str_ends_with(strtolower($image), '.png') ? 'image/png' : 'image/webp';

                $extracted = $ai->extractDishComposition(base64_encode($bytes), $mime);
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->warn("Lỗi \"{$dish->name}\": {$e->getMessage()}");
                usleep($sleepMs * 1000);
                continue;
            }

            $ingredients = array_values(array_filter(
                $extracted['ingredients'],
                fn ($i) => $i['name'] !== '' && $i['grams'] !== null && $i['grams'] > 0
            ));

            $totalGrams = $extracted['total_grams']
                ?? (count($ingredients) ? array_sum(array_column($ingredients, 'grams')) : null);

            if ($extracted['confidence'] !== 'high' || !$totalGrams || !count($ingredients)) {
                $noData++;
                continue;
            }

            if (!$dryRun) {
                DB::transaction(function () use ($dish, $totalGrams, $ingredients) {
                    $dish->update(['reference_grams' => round($totalGrams, 1)]);
                    $dish->ingredients()->delete();
                    foreach ($ingredients as $i => $ing) {
                        DishIngredient::create([
                            'dish_id' => $dish->id,
                            'name'    => mb_substr($ing['name'], 0, 150),
                            'grams'   => $ing['grams'],
                            'kcal'    => $ing['kcal'],
                            'order'   => $i,
                        ]);
                    }
                });
            }
            $ok++;

            usleep($sleepMs * 1000);
        }

        $bar->finish();
        $this->newLine(2);
        $this->table(
            ['Kết quả', 'Số lượng'],
            [
                ['Backfill thành công', $ok],
                ['Không có ảnh nguồn', $noImage],
                ['Ảnh không có bảng thành phần', $noData],
                ['Lỗi (network/Gemini)', $failed],
            ]
        );

        if ($dryRun) {
            $this->comment('(--dry-run: chưa ghi vào DB)');
        }

        return self::SUCCESS;
    }
}
