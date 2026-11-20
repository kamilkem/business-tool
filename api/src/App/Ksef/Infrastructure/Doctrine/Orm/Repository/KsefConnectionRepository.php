<?php

declare(strict_types=1);

namespace App\Ksef\Infrastructure\Doctrine\Orm\Repository;

use App\Ksef\Domain\Model\KsefConnection;
use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
