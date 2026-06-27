<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'admin_id', 'title', 'body', 'url', 'segment',
    'audience_count', 'sent_count', 'push_count', 'status', 'error',
])]
class NotificationCampaign extends Model
{
    protected function casts(): array
    {
        return ['segment' => 'array'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
