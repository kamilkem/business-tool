<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure\Doctrine\Orm\Repository;

use App\Payroll\Domain\Exception\PayRateDateTakenException;
use App\Payroll\Domain\Model\DayRate;
use App\Payroll\Domain\Repository\DayRateRepositoryInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Infrastructure\Doctrine\Type\BusinessDateType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<DayRate> */
final class DayRateRepository extends ServiceEntityRepository implements DayRateRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DayRate::class);
    }

    public function findById(Uuid $id): ?DayRate
    {
        return $this->find($id);
    }

    public function findByUserAndDate(Uuid $userId, BusinessDate $date): ?DayRate
    {
        /** @var DayRate|null */
        return $this->createQueryBuilder('r')
            ->where('r.userId = :userId')
            ->andWhere('r.date = :date')
            ->setParameter('userId', $userId, UuidType::NAME)
            ->setParameter('date', $date, BusinessDateType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findAll(?Uuid $userId = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->orderBy('r.userId', 'ASC')
            ->addOrderBy('r.date', 'DESC');
        if (null !== $userId) {
            $qb->where('r.userId = :userId')->setParameter('userId', $userId, UuidType::NAME);
        }

        /** @var list<DayRate> */
        return $qb->getQuery()->getResult();
    }

    public function findIn(BusinessPeriod $period, ?Uuid $userId = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.date >= :first')
            ->andWhere('r.date <= :last')
            ->setParameter('first', $period->first, BusinessDateType::NAME)
            ->setParameter('last', $period->last, BusinessDateType::NAME);
        if (null !== $userId) {
            $qb->andWhere('r.userId = :userId')->setParameter('userId', $userId, UuidType::NAME);
        }

        /** @var list<DayRate> */
        return $qb->getQuery()->getResult();
    }

    public function add(DayRate $rate): void
    {
        $this->getEntityManager()->persist($rate);
        $this->flush($rate);
    }

    public function save(DayRate $rate): void
    {
        $this->flush($rate);
    }

    public function remove(DayRate $rate): void
    {
        $this->getEntityManager()->remove($rate);
        $this->getEntityManager()->flush();
    }

    private function flush(DayRate $rate): void
    {
        try {
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), DayRate::ONE_PER_USER_AND_DATE)) {
                throw $e;
            }

            throw PayRateDateTakenException::dayRate($rate->getUserId()->toRfc4122(), $rate->getDate());
        }
    }
}
