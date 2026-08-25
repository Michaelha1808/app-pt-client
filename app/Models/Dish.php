<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dish extends Model
{
    protected $fillable = [
        'name',
        'name_normalized',
        'aliases',
        'unit_type',
        'unit_label',
        'serving',
        'calories',
        'protein',
        'carbs',
        'fat',
        'sodium',
    ];

    protected $casts = [
        'aliases'  => 'array',
        'calories' => 'float',
        'protein'  => 'integer',
        'carbs'    => 'integer',
        'fat'      => 'integer',
        'sodium'   => 'integer',
    ];

    public function recipes(): HasMany
    {
        return $this->hasMany(DishRecipe::class)->orderBy('order');
    }
}
