<?php

namespace Database\Seeders;

use App\Models\Dish;
use App\Services\DishCatalogService;
use Illuminate\Database\Seeder;

/**
 * Bộ món Việt phổ biến khởi tạo thư viện nutrition (grounding).
 * Idempotent: chạy lại không tạo trùng (updateOrCreate theo name_normalized).
 * Giá trị dinh dưỡng là ước tính cho 1 ĐƠN VỊ CHUẨN — mở rộng/hiệu chỉnh dần theo dataset thực tế.
 */
class DishCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->dishes() as $d) {
            $d['name_normalized'] = DishCatalogService::normalize($d['name']);
            Dish::updateOrCreate(
                ['name_normalized' => $d['name_normalized']],
                $d,
            );
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function dishes(): array
    {
        // [name, aliases, unit_type, unit_label, serving, calories, protein, carbs, fat, sodium]
        $rows = [
            ['Phở bò', ['pho', 'pho bo tai', 'pho bo chin'], 'portion', 'tô', '1 tô (~500ml)', 450, 25, 55, 12, 1500],
            ['Phở gà', ['pho ga'], 'portion', 'tô', '1 tô (~500ml)', 400, 28, 50, 8, 1400],
            ['Bún bò Huế', ['bun bo', 'bun bo hue'], 'portion', 'tô', '1 tô (~500ml)', 500, 28, 55, 16, 1700],
            ['Bún chả', ['bun cha'], 'portion', 'phần', '1 phần (bún + chả)', 500, 30, 60, 14, 1200],
            ['Bún riêu', ['bun rieu', 'bun rieu cua'], 'portion', 'tô', '1 tô (~500ml)', 400, 18, 50, 12, 1300],
            ['Bún thịt nướng', ['bun thit nuong'], 'portion', 'tô', '1 tô', 480, 26, 58, 14, 900],
            ['Bún đậu mắm tôm', ['bun dau', 'bun dau mam tom'], 'portion', 'phần', '1 mẹt', 600, 24, 55, 30, 1500],
            ['Hủ tiếu', ['hu tieu', 'hu tieu nam vang'], 'portion', 'tô', '1 tô', 400, 22, 50, 10, 1300],
            ['Mì Quảng', ['mi quang'], 'portion', 'tô', '1 tô', 450, 24, 52, 14, 1200],
            ['Cơm trắng', ['com', 'com trang'], 'portion', 'chén', '1 chén (~200ml)', 200, 4, 44, 0, 5],
            ['Cơm tấm sườn', ['com tam', 'com tam suon', 'com suon'], 'portion', 'đĩa', '1 đĩa', 650, 30, 70, 25, 1200],
            ['Cơm gà', ['com ga', 'com ga xoi mo'], 'portion', 'đĩa', '1 đĩa', 550, 32, 65, 16, 1000],
            ['Cơm chiên Dương Châu', ['com chien', 'com rang', 'com chien duong chau'], 'portion', 'đĩa', '1 đĩa', 600, 18, 75, 22, 1100],
            ['Bánh mì thịt', ['banh mi', 'banh mi thit', 'banh my'], 'countable', 'ổ', '1 ổ', 400, 16, 50, 14, 900],
            ['Bánh xèo', ['banh xeo'], 'countable', 'cái', '1 cái', 300, 10, 30, 16, 600],
            ['Gỏi cuốn', ['goi cuon', 'nem cuon'], 'countable', 'cuốn', '1 cuốn', 80, 5, 10, 2, 250],
            ['Chả giò', ['cha gio', 'nem ran', 'nem', 'cha gio ran'], 'countable', 'cái', '1 cái', 90, 4, 8, 5, 200],
            ['Xôi', ['xoi', 'xoi man', 'xoi xeo'], 'portion', 'phần', '1 phần', 350, 8, 60, 8, 400],
            ['Cháo', ['chao', 'chao thit'], 'portion', 'tô', '1 tô', 250, 10, 40, 5, 800],
            ['Bánh cuốn', ['banh cuon'], 'portion', 'phần', '1 phần', 300, 12, 45, 8, 900],
            ['Canh chua', ['canh chua', 'canh chua ca'], 'portion', 'chén', '1 chén', 150, 10, 15, 5, 800],
            ['Rau muống xào', ['rau muong xao', 'rau xao'], 'portion', 'đĩa', '1 đĩa', 120, 4, 10, 8, 500],
            ['Thịt kho tàu', ['thit kho', 'thit kho tau', 'thit kho trung'], 'portion', 'phần', '1 phần', 350, 22, 8, 26, 1100],
            ['Cá kho', ['ca kho', 'ca kho to'], 'portion', 'phần', '1 phần', 250, 24, 6, 14, 1000],
            ['Gà rán', ['ga ran', 'ga chien'], 'countable', 'miếng', '1 miếng', 250, 18, 12, 15, 600],
            ['Trứng chiên', ['trung chien', 'trung op la', 'trung ran'], 'countable', 'quả', '1 quả', 90, 6, 1, 7, 150],
            ['Đậu hũ sốt cà', ['dau hu', 'dau hu sot ca', 'dau phu'], 'portion', 'phần', '1 phần', 180, 12, 12, 9, 600],
            ['Salad', ['salad', 'rau tron', 'salad rau'], 'portion', 'đĩa', '1 đĩa', 150, 5, 12, 9, 400],
            ['Chè', ['che', 'che dau'], 'portion', 'chén', '1 chén', 250, 4, 50, 4, 50],
            ['Trà sữa', ['tra sua', 'tra sua tran chau'], 'portion', 'ly', '1 ly (~500ml)', 350, 4, 60, 10, 100],
            ['Cà phê sữa', ['ca phe sua', 'cafe sua', 'bac xiu'], 'portion', 'ly', '1 ly', 120, 3, 18, 4, 60],
            ['Nước ngọt', ['nuoc ngot', 'coca', 'pepsi', 'soda'], 'portion', 'lon', '1 lon (~330ml)', 140, 0, 35, 0, 30],
            ['Sinh tố bơ', ['sinh to bo', 'sinh to'], 'portion', 'ly', '1 ly', 300, 5, 35, 16, 60],
            ['Bánh chưng', ['banh chung'], 'countable', 'miếng', '1 miếng', 300, 8, 45, 10, 500],
        ];

        return array_map(fn ($r) => [
            'name'       => $r[0],
            'aliases'    => $r[1],
            'unit_type'  => $r[2],
            'unit_label' => $r[3],
            'serving'    => $r[4],
            'calories'   => $r[5],
            'protein'    => $r[6],
            'carbs'      => $r[7],
            'fat'        => $r[8],
            'sodium'     => $r[9],
        ], $rows);
    }
}
