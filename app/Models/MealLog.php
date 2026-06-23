<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealLog extends Model
{
    protected $fillable = [
        'user_id', 'food_name', 'serving',
        'calories', 'protein', 'carbs', 'fat', 'sodium',
        'logged_at',
    ];

    protected function casts(): array
    {
        return [
            'logged_at' => 'datetime',
            'calories'  => 'integer',
            'protein'   => 'integer',
            'carbs'     => 'integer',
            'fat'       => 'integer',
            'sodium'    => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
