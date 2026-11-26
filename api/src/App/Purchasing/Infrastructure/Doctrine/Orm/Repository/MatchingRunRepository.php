<?php

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Doctrine\Orm\Repository;

use App\Purchasing\Domain\Model\MatchingRun;
use App\Purchasing\Domain\Repository\MatchingRunRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<MatchingRun> */
final class MatchingRunRepository extends ServiceEntityRepository implements MatchingRunRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MatchingRun::class);
    }

    public function add(MatchingRun $run): void
    {
        $this->getEntityManager()->persist($run);
        $this->getEntityManager()->flush();
    }

    public function save(MatchingRun $run): void
    {
        $this->getEntityManager()->flush();
    }

    public function findById(Uuid $id): ?MatchingRun
    {
        return $this->find($id);
    }

    public function findLatest(): ?MatchingRun
    {
        /** @var MatchingRun|null */
        return $this->createQueryBuilder('r')
            ->orderBy('r.startedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
