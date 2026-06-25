<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserStreak extends Model
{
    protected $fillable = [
        'user_id',
        'current_streak',
        'best_streak',
        'last_activity_date',
        'freeze_tokens',
        'freeze_last_used_date',
    ];

    protected function casts(): array
    {
        return [
            'current_streak'        => 'integer',
            'best_streak'           => 'integer',
            'freeze_tokens'         => 'integer',
            'last_activity_date'    => 'date',
            'freeze_last_used_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
