<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatPromptLog extends Model
{
    protected $fillable = [
        'user_id', 'user_message', 'final_prompt', 'reply', 'model', 'in_scope',
    ];

    protected function casts(): array
    {
        return [
            'in_scope' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
