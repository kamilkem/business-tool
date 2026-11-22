<?php

declare(strict_types=1);

namespace App\Organization\Application\Query\GetMemberName;

final readonly class MemberNameView
{
    public function __construct(
        public string $userId,
        public string $firstName,
        public string $lastName,
    ) {
    }
}
