<?php

declare(strict_types=1);

namespace App\User\Application\Command\RotateRefreshToken;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\User\Domain\Exception\InvalidRefreshTokenException;
use App\User\Domain\Model\RefreshTokenSecret;
use App\User\Domain\Repository\RefreshTokenRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class RotateRefreshTokenHandler implements CommandHandlerInterface
{
    public function __construct(
        private RefreshTokenRepositoryInterface $tokens,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvalidRefreshTokenException when the token is unknown, revoked or expired, or was
     *                                      rotated before the grace period (then the whole session
     *                                      is revoked first)
     */
    public function __invoke(RotateRefreshTokenCommand $command): void
    {
        $token = $this->tokens->findBySecretHash(RefreshTokenSecret::hashOf($command->presentedSecret))
            ?? throw InvalidRefreshTokenException::create();

        $now = $this->clock->now();
        if ($token->isReusedAt($now)) {
            // Someone kept an old copy of the cookie: end the session for them and for its owner.
            foreach ($this->tokens->findUsableBySession($token->getSessionId(), $now) as $sessionToken) {
                $sessionToken->revoke($now);
                $this->tokens->save($sessionToken);
            }

            throw InvalidRefreshTokenException::create();
        }

        $successor = $token->rotate(
            Uuid::fromString($command->successorId),
            RefreshTokenSecret::fromString($command->successorSecret),
            $now,
        );
        $this->tokens->save($token);
        $this->tokens->add($successor);
    }
}
