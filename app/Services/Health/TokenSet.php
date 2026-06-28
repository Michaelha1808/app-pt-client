<?php

namespace App\Services\Health;

use Carbon\CarbonInterface;

/**
 * Kết quả trao đổi/refresh token từ provider — bất biến.
 */
class TokenSet
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly ?CarbonInterface $expiresAt,
        public readonly ?string $providerUserId,
        public readonly ?string $scopes = null,
    ) {}
}
