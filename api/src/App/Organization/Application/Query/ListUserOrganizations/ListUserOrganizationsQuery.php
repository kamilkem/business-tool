<?php

declare(strict_types=1);

namespace App\Organization\Application\Query\ListUserOrganizations;

use App\Shared\Application\Query\QueryInterface;

final readonly class ListUserOrganizationsQuery implements QueryInterface
{
    public function __construct(
        public string $userId,
    ) {
    }
}
