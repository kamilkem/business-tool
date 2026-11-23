<?php

declare(strict_types=1);

namespace App\Income\Infrastructure\Doctrine\Orm\Repository;

use App\Income\Domain\Model\RevenueEntryChange;
use App\Income\Domain\Repository\RevenueEntryChangeRepositoryInterface;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Infrastructure\Doctrine\Type\BusinessDateType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<RevenueEntryChange> */
final class RevenueEntryChangeRepository extends ServiceEntityRepository implements RevenueEntryChangeRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RevenueEntryChange::class);
    }

    public function add(RevenueEntryChange $change): void
    {
        $this->getEntityManager()->persist($change);
        $this->getEntityManager()->flush();
    }

    public function findByEntryId(Uuid $entryId): array
    {
        /** @var list<RevenueEntryChange> */
        return $this->createQueryBuilder('c')
            ->where('c.entryId = :entryId')
            ->setParameter('entryId', $entryId, UuidType::NAME)
            ->orderBy('c.changedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findInPeriod(BusinessPeriod $period): array
    {
        /** @var list<RevenueEntryChange> */
        return $this->createQueryBuilder('c')
            ->where('c.businessDate >= :first')
            ->andWhere('c.businessDate <= :last')
            ->setParameter('first', $period->first, BusinessDateType::NAME)
            ->setParameter('last', $period->last, BusinessDateType::NAME)
            ->orderBy('c.changedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
