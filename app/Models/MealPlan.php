<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealPlan extends Model
{
    protected $fillable = [
        'user_id', 'scope', 'target_date', 'plan',
        'reasoning', 'context_snapshot', 'data_hash',
    ];

    protected function casts(): array
    {
        return [
            'target_date'      => 'date',
            'plan'             => 'array',
            'context_snapshot' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
