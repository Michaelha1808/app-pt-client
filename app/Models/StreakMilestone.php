<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StreakMilestone extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'days', 'achieved_at', 'push_sent_at'];

    protected function casts(): array
    {
        return [
            'days'         => 'integer',
            'achieved_at'  => 'datetime',
            'push_sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
