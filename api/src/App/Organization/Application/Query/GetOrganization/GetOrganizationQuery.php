<?php

declare(strict_types=1);

namespace App\Organization\Application\Query\GetOrganization;

use App\Shared\Application\Query\QueryInterface;

/** Returns null when the organization does not exist or the user is not a member. */
final readonly class GetOrganizationQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $userId,
    ) {
    }
}
