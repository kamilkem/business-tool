<?php

declare(strict_types=1);

namespace App\Income\Infrastructure\Doctrine\Orm\Repository;

use App\Income\Domain\Exception\RevenueMonthAlreadyClosedException;
use App\Income\Domain\Model\RevenueMonth;
use App\Income\Domain\Model\RevenueMonthLocks;
use App\Income\Domain\Repository\RevenueMonthRepositoryInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Infrastructure\Doctrine\Type\BusinessDateType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RevenueMonth> */
final class RevenueMonthRepository extends ServiceEntityRepository implements RevenueMonthRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RevenueMonth::class);
    }

    public function findByMonth(BusinessPeriod $month): ?RevenueMonth
    {
        /** @var RevenueMonth|null */
        return $this->createQueryBuilder('m')
            ->where('m.month = :month')
            ->setParameter('month', BusinessPeriod::monthOf($month->first)->first, BusinessDateType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function locks(): RevenueMonthLocks
    {
        /** @var list<array{month: BusinessDate}> $rows */
        $rows = $this->createQueryBuilder('m')
            ->select('m.month')
            ->where('m.closedAt IS NOT NULL')
            ->getQuery()
            ->getResult();

        return new RevenueMonthLocks(array_map(static fn (array $row): string => substr($row['month']->toString(), 0, 7), $rows));
    }

    public function add(RevenueMonth $month): void
    {
        $this->getEntityManager()->persist($month);

        try {
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), RevenueMonth::ONE_PER_MONTH)) {
                throw $e;
            }

            throw RevenueMonthAlreadyClosedException::of($month->monthString());
        }
    }

    public function save(RevenueMonth $month): void
    {
        $this->getEntityManager()->flush();
    }
}
