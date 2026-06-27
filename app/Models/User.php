<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'email', 'password', 'google_id', 'facebook_id', 'provider',
    'avatar_url', 'birth_year', 'gender', 'height_cm', 'weight_kg',
    'calorie_goal', 'morning_notify', 'evening_notify', 'calorie_streak',
    'morning_notify_enabled', 'midday_notify_enabled', 'evening_notify_enabled',
    'email_reengagement_enabled', 'last_seen_at', 'reengagement_sent_at',
    'role', 'status', 'suspend_reason',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, SoftDeletes;

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    public function mealLogs(): HasMany
    {
        return $this->hasMany(MealLog::class);
    }

    public function mealPlans(): HasMany
    {
        return $this->hasMany(MealPlan::class);
    }

    public function notificationSubscriptions(): HasMany
    {
        return $this->hasMany(NotificationSubscription::class);
    }

    public function webauthnCredentials(): HasMany
    {
        return $this->hasMany(WebauthnCredential::class);
    }

    public function streak(): HasOne
    {
        return $this->hasOne(UserStreak::class);
    }

    public function waterLogs(): HasMany
    {
        return $this->hasMany(WaterLog::class);
    }

    public function streakMilestones(): HasMany
    {
        return $this->hasMany(StreakMilestone::class);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'birth_year'        => 'integer',
            'height_cm'         => 'decimal:1',
            'weight_kg'         => 'decimal:1',
            'calorie_goal'      => 'integer',
            'calorie_streak'              => 'integer',
            'morning_notify_enabled'      => 'boolean',
            'midday_notify_enabled'       => 'boolean',
            'evening_notify_enabled'      => 'boolean',
            'email_reengagement_enabled'  => 'boolean',
            'last_seen_at'                => 'datetime',
            'reengagement_sent_at'        => 'datetime',
        ];
    }
}
