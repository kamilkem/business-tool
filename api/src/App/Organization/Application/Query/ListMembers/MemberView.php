<?php

declare(strict_types=1);

namespace App\Organization\Application\Query\ListMembers;

use App\Organization\Domain\Model\Membership;

final readonly class MemberView
{
    public function __construct(
        public string $userId,
        public string $email,
        public string $firstName,
        public string $lastName,
        public string $role,
        public \DateTimeImmutable $joinedAt,
        public bool $invitationPending,
    ) {
    }

    public static function fromMembership(Membership $membership): self
    {
        $user = $membership->getUser();

        return new self(
            $user->getId()->toRfc4122(),
            $user->getEmail(),
            $user->getFirstName(),
            $user->getLastName(),
            $membership->getRole()->value,
            $membership->getCreatedAt(),
            !$user->hasPassword(),
        );
    }
}
