<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HealthActivity extends Model
{
    protected $fillable = [
        'user_id', 'health_connection_id', 'provider', 'external_id', 'source',
        'type', 'name', 'started_at', 'duration_seconds',
        'distance_meters', 'calories', 'raw',
    ];

    protected function casts(): array
    {
        return [
            'started_at'       => 'datetime',
            'duration_seconds' => 'integer',
            'distance_meters'  => 'integer',
            'calories'         => 'integer',
            'raw'              => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(HealthConnection::class, 'health_connection_id');
    }
}
