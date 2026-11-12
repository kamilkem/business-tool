<?php

declare(strict_types=1);

namespace App\User\Application\Query\GetRefreshToken;

use App\Shared\Application\Query\QueryInterface;

/**
 * A refresh token just issued or rotated, for the sign-in and refresh responses (ADR 0020): who it
 * belongs to and when its cookie expires. Null when there is none.
 */
final readonly class GetRefreshTokenQuery implements QueryInterface
{
    public function __construct(
        public string $refreshTokenId,
    ) {
    }
}
