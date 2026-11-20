<?php

declare(strict_types=1);

namespace App\Ksef\Application\Query\ListKsefOrganizationsToImport;

use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class ListKsefOrganizationsToImportHandler implements QueryHandlerInterface
{
    public function __construct(private KsefConnectionRepositoryInterface $connections)
    {
    }

    /** @return list<string> */
    public function __invoke(ListKsefOrganizationsToImportQuery $query): array
    {
        return $this->connections->findOrganizationIdsToSync(new \DateTimeImmutable($query->now));
    }
}
