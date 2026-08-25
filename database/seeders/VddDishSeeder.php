<?php

namespace Database\Seeders;

use App\Models\Dish;
use App\Support\VietnameseText;
use Illuminate\Database\Seeder;

/**
 * Nạp thư viện `dishes` bằng ~1248 món ăn thật từ Viện Dinh dưỡng Quốc gia.
 *
 * Nguồn: https://viendinhduong.vn/vi/cong-cu-va-tien-ich/gia-tri-dinh-duong-mon-an
 * (API /api/fe/tool/getPageFoodData, crawl 2026-08-25). Món trùng tên giữa các
 * vùng miền được giữ lại đầy đủ, gắn hậu tố "(Vùng miền)" để phân biệt; lưu sẵn
 * ở data/vdd_dishes.json.
 *
 * Dữ liệu cũ trong `dishes` (33 món DEFENSE ước tính) đã được backup ở
 * database/backups/dishes_backup_2026-08-25.json rồi TRUNCATE trước khi seed lại.
 * Idempotent: bỏ qua món đã có (khớp theo name_normalized) nếu chạy lại mà chưa truncate.
 */
class VddDishSeeder extends Seeder
{
    public function run(): void
    {
        $path = __DIR__.'/data/vdd_dishes.json';
        $rows = json_decode(file_get_contents($path), true);

        $existing = Dish::pluck('name_normalized')->flip();

        $inserted = 0;
        foreach ($rows as $r) {
            $normalized = VietnameseText::normalize($r['name']);
            if ($normalized === '' || isset($existing[$normalized])) {
                continue;
            }

            $aliases = array_values(array_unique(array_filter([
                $r['name_ascii'] ?? null,
                $r['name_en'] ?? null,
            ])));

            Dish::create([
                'name'            => $r['name'],
                'name_normalized' => $normalized,
                'aliases'         => $aliases,
                'unit_type'       => 'portion',
                'unit_label'      => 'phần',
                'serving'         => '1 phần (theo Viện Dinh dưỡng)',
                'calories'        => $r['calories'],
                'protein'         => $r['protein'],
                'carbs'           => $r['carbs'],
                'fat'             => $r['fat'],
                'sodium'          => $r['sodium'],
            ]);

            $existing[$normalized] = true;
            $inserted++;
        }

        $this->command?->info("VddDishSeeder: đã thêm {$inserted} món / ".count($rows)." món nguồn.");
    }
}
