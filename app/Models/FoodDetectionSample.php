<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FoodDetectionSample extends Model
{
    protected $fillable = [
        'user_id',
        'input_type',
        'image_path',
        'text_input',
        'model',
        'ai_dishes',
        'corrected_dishes',
        'has_correction',
        'saved',
    ];

    protected $casts = [
        'ai_dishes'        => 'array',
        'corrected_dishes' => 'array',
        'has_correction'   => 'boolean',
        'saved'            => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
