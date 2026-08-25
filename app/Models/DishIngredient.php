<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DishIngredient extends Model
{
    protected $fillable = [
        'dish_id',
        'name',
        'grams',
        'kcal',
        'order',
    ];

    protected $casts = [
        'grams' => 'float',
        'kcal'  => 'float',
    ];
}
