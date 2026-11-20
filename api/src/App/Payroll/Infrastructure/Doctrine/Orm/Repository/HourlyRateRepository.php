<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure\Doctrine\Orm\Repository;

use App\Payroll\Domain\Exception\PayRateDateTakenException;
use App\Payroll\Domain\Model\HourlyRate;
use App\Payroll\Domain\Repository\HourlyRateRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<HourlyRate> */
final class HourlyRateRepository extends ServiceEntityRepository implements HourlyRateRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HourlyRate::class);
    }

    public function findById(Uuid $id): ?HourlyRate
    {
        return $this->find($id);
    }

    public function findByUser(Uuid $userId): array
    {
        /** @var list<HourlyRate> */
        return $this->createQueryBuilder('r')
            ->where('r.userId = :userId')
            ->setParameter('userId', $userId, UuidType::NAME)
            ->orderBy('r.effectiveFrom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAll(?Uuid $userId = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->orderBy('r.userId', 'ASC')
            ->addOrderBy('r.effectiveFrom', 'DESC');
        if (null !== $userId) {
            $qb->where('r.userId = :userId')->setParameter('userId', $userId, UuidType::NAME);
        }

        /** @var list<HourlyRate> */
        return $qb->getQuery()->getResult();
    }

    public function add(HourlyRate $rate): void
    {
        $this->getEntityManager()->persist($rate);
        $this->flush($rate);
    }

    public function save(HourlyRate $rate): void
    {
        $this->flush($rate);
    }

    public function remove(HourlyRate $rate): void
    {
        $this->getEntityManager()->remove($rate);
        $this->getEntityManager()->flush();
    }

    private function flush(HourlyRate $rate): void
    {
        try {
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), HourlyRate::ONE_PER_USER_AND_DATE)) {
                throw $e;
            }

            throw PayRateDateTakenException::hourlyRate($rate->getUserId()->toRfc4122(), $rate->getEffectiveFrom());
        }
    }
}
