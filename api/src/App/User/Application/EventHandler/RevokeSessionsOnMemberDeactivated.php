<?php

declare(strict_types=1);

namespace App\User\Application\EventHandler;

use App\Organization\Domain\Event\MemberDeactivated;
use App\Organization\Domain\Repository\MembershipRepositoryInterface;
use App\Shared\Domain\Event\EventHandlerInterface;
use App\User\Domain\Repository\RefreshTokenRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Sessions belong to the global user, memberships to one organization (ADR 0020). While the person is
 * still an active member elsewhere, their sessions stay: the membership check already shuts them out
 * of this organization. Once no active membership is left, all their sessions are revoked.
 */
final readonly class RevokeSessionsOnMemberDeactivated implements EventHandlerInterface
{
    public function __construct(
        private MembershipRepositoryInterface $memberships,
        private RefreshTokenRepositoryInterface $tokens,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(MemberDeactivated $event): void
    {
        $userId = Uuid::fromString($event->userId);
        if ([] !== $this->memberships->findActiveByUser($userId)) {
            return;
        }

        $now = $this->clock->now();
        foreach ($this->tokens->findUsableByUser($userId, $now) as $token) {
            $token->revoke($now);
            $this->tokens->save($token);
        }
    }
}
