<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 1 hàng trong Bảng Thành phần Thực phẩm VDD 2007/2017.
 * Số liệu cho 100g phần ăn được.
 *
 * @property string $vdd_code Mã số FCT VDD
 * @property string $name_vi Tên tiếng Việt
 * @property string $name_en Tên tiếng Anh
 * @property string $group_name Nhóm thực phẩm
 * @property float  $energy_kcal
 * @property float  $protein_g
 * @property float  $fat_g
 * @property float  $carbs_g
 * @property float  $fiber_g
 * @property float  $calcium_mg
 * @property float  $iron_mg
 * @property float  $sodium_mg
 * @property float  $potassium_mg
 * @property float  $zinc_mg
 * @property float  $vitamin_a_mcg
 * @property float  $vitamin_c_mg
 */
class VddFood extends Model
{
    protected $table = 'vdd_food_composition';

    protected $fillable = [
        'vdd_code', 'name_vi', 'name_en', 'group_name', 'name_normalized',
        'energy_kcal', 'protein_g', 'fat_g', 'carbs_g', 'fiber_g', 'water_g',
        'calcium_mg', 'iron_mg', 'sodium_mg', 'potassium_mg', 'zinc_mg',
        'vitamin_a_mcg', 'vitamin_c_mg',
    ];

    protected $casts = [
        'energy_kcal'   => 'float',
        'protein_g'     => 'float',
        'fat_g'         => 'float',
        'carbs_g'       => 'float',
        'fiber_g'       => 'float',
        'water_g'       => 'float',
        'calcium_mg'    => 'float',
        'iron_mg'       => 'float',
        'sodium_mg'     => 'float',
        'potassium_mg'  => 'float',
        'zinc_mg'       => 'float',
        'vitamin_a_mcg' => 'float',
        'vitamin_c_mg'  => 'float',
    ];
}
