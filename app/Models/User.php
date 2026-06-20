<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'email', 'password', 'google_id', 'provider',
    'avatar_url', 'birth_year', 'gender', 'height_cm', 'weight_kg',
    'calorie_goal', 'morning_notify', 'evening_notify', 'calorie_streak',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    public function mealLogs(): HasMany
    {
        return $this->hasMany(MealLog::class);
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
            'calorie_streak'    => 'integer',
        ];
    }
}
