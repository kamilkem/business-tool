<?php

declare(strict_types=1);

namespace App\User\Application\Command\IssueRefreshToken;

use App\Shared\Application\Command\CommandInterface;

/**
 * Starts a session at sign-in (ADR 0020): the first refresh token of a new session. The caller
 * generates the ids and the secret, because it puts the secret into the cookie.
 */
final readonly class IssueRefreshTokenCommand implements CommandInterface
{
    public function __construct(
        public string $refreshTokenId,
        public string $sessionId,
        public string $userId,
        #[\SensitiveParameter]
        public string $secret,
    ) {
    }
}
