<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MealLog extends Model
{
    protected $fillable = [
        'user_id', 'food_name', 'serving', 'image_path',
        'calories', 'protein', 'carbs', 'fat', 'sodium',
        'ai_advice', 'logged_at',
    ];

    /** URL công khai của ảnh món ăn (null nếu nhập tay). */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

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
