<?php

namespace Database\Seeders;

use App\Models\Dish;
use App\Models\DishRecipe;
use App\Models\VddFood;
use Illuminate\Database\Seeder;

/**
 * Recipe (công thức nguyên liệu) cho các món phổ biến trong `dishes`.
 *
 * Mỗi món liệt kê nguyên liệu FCT VDD kèm gram/khẩu phần chuẩn. Khối lượng
 * ước tính theo khẩu phần "1 tô/1 đĩa" thông thường ở VN. Sau khi seed,
 * `DishCompositionService::compute()` có thể tính lại calo/macro chính xác
 * hơn hardcode trong DishCatalogSeeder.
 *
 * Idempotent theo (dish_id, vdd_food_id) — updateOrCreate.
 */
class DishRecipeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->recipes() as $dishName => $ingredients) {
            $dish = Dish::where('name', $dishName)->first();
            if (!$dish) continue;

            $order = 1;
            foreach ($ingredients as $vddCode => $data) {
                $food = VddFood::where('vdd_code', $vddCode)->first();
                if (!$food) continue;

                DishRecipe::updateOrCreate(
                    ['dish_id' => $dish->id, 'vdd_food_id' => $food->id],
                    [
                        'grams_per_serving' => $data[0],
                        'note'              => $data[1] ?? null,
                        'order'             => $order++,
                    ],
                );
            }
        }
    }

    /**
     * @return array<string, array<string, array{0:float,1?:string}>>
     */
    private function recipes(): array
    {
        return [
            // Phở bò: 1 tô ~500g (bánh phở 180g + thịt bò tái 60g + hành 10g + gia vị + nước dùng)
            'Phở bò' => [
                '1012' => [180, 'bánh phở tươi'],
                '2002' => [60,  'thịt bò bắp'],
                '4015' => [10,  'hành lá'],
                '4016' => [5,   'húng quế/ngò gai'],
                '3050' => [15,  'nước mắm nêm'],
                '14015' => [3,  'muối'],
            ],

            // Phở gà: tương tự phở bò, thay bò bằng gà ức
            'Phở gà' => [
                '1012' => [180, 'bánh phở tươi'],
                '2011' => [70,  'ức gà luộc'],
                '4015' => [10,  'hành lá'],
                '4016' => [5,   'ngò rí'],
                '3050' => [15,  'nước mắm'],
                '14015' => [3,  'muối'],
            ],

            // Cơm gà: 1 đĩa (cơm 200g + gà 80g + dưa leo/cà chua 30g)
            'Cơm gà' => [
                '1002' => [200, 'cơm trắng'],
                '2011' => [80,  'ức gà luộc'],
                '4009' => [30,  'cà chua'],
                '14001' => [8,  'dầu ăn'],
                '14020' => [10, 'nước tương'],
                '14015' => [2,  'muối'],
            ],

            // Cơm tấm sườn: 1 đĩa (cơm tấm 220g + sườn nướng 100g + dưa chua 30g + trứng ốp la 50g)
            'Cơm tấm sườn' => [
                '1002' => [220, 'cơm tấm'],
                '2006' => [100, 'sườn ba chỉ nướng'],
                '11001' => [50, 'trứng ốp la'],
                '4010' => [30,  'cà rốt/dưa chua'],
                '14001' => [10, 'dầu mỡ hành'],
                '3050' => [15,  'nước mắm chấm'],
            ],

            // Bánh mì thịt: 1 ổ (bánh mì 80g + giò 40g + pate 15g + rau 20g + đồ chua 15g)
            'Bánh mì thịt' => [
                '1025' => [80,  'bánh mì'],
                '2020' => [40,  'giò lụa/chả'],
                '2005' => [25,  'thịt heo xíu mại'],
                '2030' => [15,  'pate gan'],
                '4020' => [15,  'xà lách/dưa leo'],
                '4010' => [15,  'cà rốt/củ cải chua'],
            ],

            // Bún chả: 1 phần (bún 200g + chả nướng 80g + nước chấm 50g + rau sống 40g)
            'Bún chả' => [
                '1010' => [200, 'bún tươi'],
                '2005' => [70,  'thịt viên nướng'],
                '2006' => [30,  'chả ba chỉ nướng'],
                '4020' => [30,  'xà lách/rau thơm'],
                '3050' => [30,  'nước mắm chấm'],
                '4010' => [15,  'đu đủ/cà rốt chua'],
            ],

            // Bún bò Huế: 1 tô (bún 200g + thịt bò 70g + giò heo 40g + rau 20g)
            'Bún bò Huế' => [
                '1010' => [200, 'bún tươi'],
                '2002' => [70,  'thịt bò bắp'],
                '2006' => [40,  'giò heo'],
                '4001' => [20,  'rau muống chẻ'],
                '3050' => [20,  'nước mắm/mắm ruốc'],
                '14015' => [3,  'muối'],
            ],

            // Cơm trắng: chỉ 1 nguyên liệu — dùng làm base cho các món cơm
            'Cơm trắng' => [
                '1002' => [200, 'cơm trắng nấu chín'],
            ],
        ];
    }
}
