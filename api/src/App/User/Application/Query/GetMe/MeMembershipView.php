<?php

declare(strict_types=1);

namespace App\User\Application\Query\GetMe;

final readonly class MeMembershipView
{
    public function __construct(
        public string $id,
        public string $role,
        public string $organizationId,
        public string $organizationName,
    ) {
    }
}
