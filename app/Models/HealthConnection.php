<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HealthConnection extends Model
{
    protected $fillable = [
        'user_id', 'provider', 'provider_user_id',
        'access_token', 'refresh_token', 'token_expires_at',
        'scopes', 'last_synced_at', 'webhook_id', 'status',
    ];

    protected function casts(): array
    {
        return [
            // Mã hoá at-rest bằng APP_KEY — không bao giờ trả ra API.
            'access_token'     => 'encrypted',
            'refresh_token'    => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at'   => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(HealthActivity::class);
    }
}
