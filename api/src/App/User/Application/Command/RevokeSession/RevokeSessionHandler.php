<?php

declare(strict_types=1);

namespace App\User\Application\Command\RevokeSession;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\User\Domain\Model\RefreshTokenSecret;
use App\User\Domain\Repository\RefreshTokenRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * Signing out always succeeds: an unknown, expired or already revoked token leaves nothing to end,
 * and the client clears its own session either way.
 */
final readonly class RevokeSessionHandler implements CommandHandlerInterface
{
    public function __construct(
        private RefreshTokenRepositoryInterface $tokens,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RevokeSessionCommand $command): void
    {
        $token = $this->tokens->findBySecretHash(RefreshTokenSecret::hashOf($command->presentedSecret));
        if (null === $token) {
            return;
        }

        $now = $this->clock->now();
        foreach ($this->tokens->findUsableBySession($token->getSessionId(), $now) as $sessionToken) {
            $sessionToken->revoke($now);
            $this->tokens->save($sessionToken);
        }
    }
}
