<?php

declare(strict_types=1);

namespace App\User\Application\Command\IssueRefreshToken;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\User\Domain\Exception\UserNotFoundException;
use App\User\Domain\Model\RefreshToken;
use App\User\Domain\Model\RefreshTokenSecret;
use App\User\Domain\Repository\RefreshTokenRepositoryInterface;
use App\User\Domain\Repository\UserRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class IssueRefreshTokenHandler implements CommandHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
        private RefreshTokenRepositoryInterface $tokens,
        private ClockInterface $clock,
    ) {
    }

    /** @throws UserNotFoundException */
    public function __invoke(IssueRefreshTokenCommand $command): void
    {
        $user = $this->users->findById(Uuid::fromString($command->userId))
            ?? throw UserNotFoundException::forId($command->userId);

        $this->tokens->add(RefreshToken::issueAtSignIn(
            Uuid::fromString($command->refreshTokenId),
            $user,
            Uuid::fromString($command->sessionId),
            RefreshTokenSecret::fromString($command->secret),
            $this->clock->now(),
        ));
    }
}
