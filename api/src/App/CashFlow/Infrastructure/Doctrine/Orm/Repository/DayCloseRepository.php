<?php

declare(strict_types=1);

namespace App\CashFlow\Infrastructure\Doctrine\Orm\Repository;

use App\CashFlow\Domain\Exception\DayAlreadyClosedException;
use App\CashFlow\Domain\Model\DayClose;
use App\CashFlow\Domain\Repository\DayCloseRepositoryInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Infrastructure\Doctrine\Type\BusinessDateType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<DayClose> */
final class DayCloseRepository extends ServiceEntityRepository implements DayCloseRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DayClose::class);
    }

    public function findById(Uuid $id): ?DayClose
    {
        return $this->find($id);
    }

    public function findByDate(BusinessDate $date): ?DayClose
    {
        return $this->createQueryBuilder('c')
            ->where('c.businessDate = :date')
            ->setParameter('date', $date, BusinessDateType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatest(): ?DayClose
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.businessDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findInPeriod(BusinessPeriod $period): array
    {
        /** @var list<DayClose> */
        return $this->createQueryBuilder('c')
            ->where('c.businessDate >= :from AND c.businessDate <= :to')
            ->setParameter('from', $period->first, BusinessDateType::NAME)
            ->setParameter('to', $period->last, BusinessDateType::NAME)
            ->orderBy('c.businessDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function add(DayClose $close): void
    {
        $this->getEntityManager()->persist($close);

        try {
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), DayClose::UNIQUE_DATE)) {
                throw $e;
            }

            throw DayAlreadyClosedException::concurrently($close->getBusinessDate()->toString());
        }
    }
}
