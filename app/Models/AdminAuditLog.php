<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['admin_id', 'action', 'target_type', 'target_id', 'meta', 'ip'])]
class AdminAuditLog extends Model
{
    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
