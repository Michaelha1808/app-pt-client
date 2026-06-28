<?php

namespace App\Services\Health;

use InvalidArgumentException;

class HealthProviderFactory
{
    /** @var array<string, class-string<HealthProvider>> */
    private const PROVIDERS = [
        'strava' => StravaProvider::class,
        // 'fitbit' => FitbitProvider::class,   // Phase 4
        // 'garmin' => GarminProvider::class,
    ];

    public function make(string $provider): HealthProvider
    {
        $class = self::PROVIDERS[$provider] ?? null;

        throw_unless($class, new InvalidArgumentException("Provider không hỗ trợ: {$provider}"));

        return app($class);
    }

    public function supports(string $provider): bool
    {
        return isset(self::PROVIDERS[$provider]);
    }
}
