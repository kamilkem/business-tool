<?php

declare(strict_types=1);

namespace App\User\Application\EventHandler;

use App\Organization\Domain\Event\MemberDeactivated;
use App\Organization\Domain\Repository\MembershipRepositoryInterface;
use App\Shared\Domain\Event\EventHandlerInterface;
use App\User\Domain\Exception\UserNotFoundException;
use App\User\Domain\Repository\PasswordTokenRepositoryInterface;
use App\User\Domain\Repository\UserRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A deactivated member whose invitation is still pending must not be able to set a password with it
 * (ADR 0013): their unused tokens are invalidated. Tokens are per user, not per organization, so
 * they are kept while the person is still an active member of another organization, whose invitation
 * the link may be for. Someone who already has a password is left alone: they keep their account.
 */
final readonly class InvalidateInvitationOnMemberDeactivated implements EventHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
        private MembershipRepositoryInterface $memberships,
        private PasswordTokenRepositoryInterface $tokens,
        private ClockInterface $clock,
    ) {
    }

    /** @throws UserNotFoundException */
    public function __invoke(MemberDeactivated $event): void
    {
        $userId = Uuid::fromString($event->userId);
        $user = $this->users->findById($userId) ?? throw UserNotFoundException::forId($event->userId);
        if ($user->hasPassword() || [] !== $this->memberships->findActiveByUser($userId)) {
            return;
        }

        $now = $this->clock->now();
        foreach ($this->tokens->findUnusedByUser($userId) as $token) {
            $token->invalidate($now);
            $this->tokens->save($token);
        }
    }
}
