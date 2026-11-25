<?php

declare(strict_types=1);

namespace App\Ksef\Infrastructure\Doctrine\Orm\Repository;

use App\Ksef\Domain\Model\KsefConnection;
use App\Ksef\Domain\Model\KsefConnectionStatus;
use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<KsefConnection> */
final class KsefConnectionRepository extends ServiceEntityRepository implements KsefConnectionRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KsefConnection::class);
    }

    public function findCurrent(): ?KsefConnection
    {
        return $this->findOneBy([]);
    }

    public function findOrganizationIdsToSync(\DateTimeImmutable $now): array
    {
        // Plain SQL on purpose: the TenantFilter applies to ORM queries only, and this list spans
        // organizations. It returns ids and nothing else.
        /** @var list<string> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT organization_id FROM ksef_connection
             WHERE status = :active AND secret_id IS NOT NULL
               AND (sync_retry_not_before IS NULL OR sync_retry_not_before <= :now)
             ORDER BY organization_id',
            ['active' => KsefConnectionStatus::Active->value, 'now' => $now->setTimezone(new \DateTimeZone('UTC'))],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        return $ids;
    }

    public function findOrganizationIds(): array
    {
        // Plain SQL for the same reason as findOrganizationIdsToSync().
        /** @var list<string> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn('SELECT organization_id FROM ksef_connection ORDER BY organization_id');

        return $ids;
    }

    public function add(KsefConnection $connection): void
    {
        $this->getEntityManager()->persist($connection);
        $this->getEntityManager()->flush();
    }

    public function save(KsefConnection $connection): void
    {
        $this->getEntityManager()->flush();
    }
}
