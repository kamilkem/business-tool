<?php

declare(strict_types=1);

namespace App\TimeTracking\Infrastructure\Doctrine\Orm\Repository;

use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Infrastructure\Doctrine\Type\BusinessDateType;
use App\TimeTracking\Domain\Model\LockedShiftPeriod;
use App\TimeTracking\Domain\Repository\LockedShiftPeriodRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<LockedShiftPeriod> */
final class LockedShiftPeriodRepository extends ServiceEntityRepository implements LockedShiftPeriodRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LockedShiftPeriod::class);
    }

    public function findAll(): array
    {
        /** @var list<LockedShiftPeriod> */
        return $this->createQueryBuilder('p')
            ->orderBy('p.firstDate', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByLockedBy(Uuid $lockedBy): ?LockedShiftPeriod
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.lockedBy = :lockedBy')
            ->setParameter('lockedBy', $lockedBy, UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findContaining(BusinessDate $date): ?LockedShiftPeriod
    {
        /** @var LockedShiftPeriod|null */
        return $this->createQueryBuilder('p')
            ->andWhere('p.firstDate <= :date')
            ->andWhere('p.lastDate >= :date')
            ->setParameter('date', $date, BusinessDateType::NAME)
            ->orderBy('p.firstDate', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function add(LockedShiftPeriod $period): void
    {
        $this->getEntityManager()->persist($period);
        $this->getEntityManager()->flush();
    }

    public function remove(LockedShiftPeriod $period): void
    {
        $this->getEntityManager()->remove($period);
        $this->getEntityManager()->flush();
    }
}
