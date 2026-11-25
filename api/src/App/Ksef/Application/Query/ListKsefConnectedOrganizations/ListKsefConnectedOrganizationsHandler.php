<?php

declare(strict_types=1);

namespace App\Ksef\Application\Query\ListKsefConnectedOrganizations;

use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class ListKsefConnectedOrganizationsHandler implements QueryHandlerInterface
{
    public function __construct(private KsefConnectionRepositoryInterface $connections)
    {
    }

    /** @return list<string> */
    public function __invoke(ListKsefConnectedOrganizationsQuery $query): array
    {
        return $this->connections->findOrganizationIds();
    }
}
