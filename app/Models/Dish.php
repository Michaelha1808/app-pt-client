<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        'calories' => 'integer',
        'protein'  => 'integer',
        'carbs'    => 'integer',
        'fat'      => 'integer',
        'sodium'   => 'integer',
    ];
}
