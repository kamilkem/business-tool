<?php

declare(strict_types=1);

namespace App\User\Application\Command\RotateRefreshToken;

use App\Shared\Application\Command\CommandInterface;

/**
 * Spends the presented refresh token on a successor in the same session (ADR 0020). The caller
 * generates the successor's id and secret, because it puts the secret into the cookie.
 */
final readonly class RotateRefreshTokenCommand implements CommandInterface
{
    public function __construct(
        #[\SensitiveParameter]
        public string $presentedSecret,
        public string $successorId,
        #[\SensitiveParameter]
        public string $successorSecret,
    ) {
    }
}
