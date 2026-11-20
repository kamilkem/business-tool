<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ksef\Application;

use App\Ksef\Domain\Model\KsefConnection;
use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;

/** Fake implementing the port for one organization: no container, no database. */
final class InMemoryKsefConnectionRepository implements KsefConnectionRepositoryInterface
{
    private ?KsefConnection $connection = null;

    public int $saveCallCount = 0;

    public function findCurrent(): ?KsefConnection
    {
        return $this->connection;
    }

    public function findOrganizationIdsToSync(\DateTimeImmutable $now): array
    {
        if (null === $this->connection || !$this->connection->canSync($now)) {
            return [];
        }
        $organization = $this->connection->getOrganization();

        return null === $organization ? [] : [$organization->getId()->toRfc4122()];
    }

    public function add(KsefConnection $connection): void
    {
        if (null !== $this->connection) {
            throw new \LogicException('The organization already has a KSeF connection.');
        }

        $this->connection = $connection;
    }

    public function save(KsefConnection $connection): void
    {
        ++$this->saveCallCount;
        $this->connection = $connection;
    }
}
