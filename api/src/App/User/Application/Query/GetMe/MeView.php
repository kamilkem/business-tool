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
        /** A Language code; null when the user has never chosen one (ADR 0028 §2). */
        public ?string $language,
        public array $memberships,
    ) {
    }
}
