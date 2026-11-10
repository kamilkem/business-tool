<?php

declare(strict_types=1);

namespace App\User\Application\Query\GetMe;

final readonly class MeView
{
    /** @param list<MeMembershipView> $memberships */
    public function __construct(
        public string $id,
        public string $email,
        public string $firstName,
        public string $lastName,
        public array $memberships,
    ) {
    }
}
