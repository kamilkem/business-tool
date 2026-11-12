<?php

declare(strict_types=1);

namespace App\TimeTracking\Infrastructure\Doctrine\Orm\Repository;

use App\Shared\Domain\Time\UtcRange;
use App\TimeTracking\Domain\Exception\ShiftAlreadyOpenException;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Shift> */
final class ShiftRepository extends ServiceEntityRepository implements ShiftRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Shift::class);
    }

    public function findById(Uuid $id): ?Shift
    {
        return $this->find($id);
    }

    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Shift> */
        return $this->createQueryBuilder('s')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids))
            ->getQuery()
            ->getResult();
    }

    public function findOpenByUser(Uuid $userId): ?Shift
    {
        return $this->findOneBy(['userId' => $userId, 'endedAt' => null]);
    }

    public function findStartedIn(UtcRange $range, ?Uuid $userId = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.startedAt >= :from')
            ->andWhere('s.startedAt < :to')
            ->setParameter('from', $range->from, Types::DATETIME_IMMUTABLE)
            ->setParameter('to', $range->to, Types::DATETIME_IMMUTABLE)
            ->orderBy('s.startedAt', 'DESC')
            ->addOrderBy('s.id', 'DESC');

        if (null !== $userId) {
            $qb->andWhere('s.userId = :userId')->setParameter('userId', $userId, UuidType::NAME);
        }

        /** @var list<Shift> */
        return $qb->getQuery()->getResult();
    }

    public function findOverlapping(Uuid $userId, \DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.userId = :userId')
            ->andWhere('s.endedAt IS NULL OR s.endedAt > :from')
            ->setParameter('userId', $userId, UuidType::NAME)
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->orderBy('s.startedAt', 'ASC');

        if (null !== $to) {
            $qb->andWhere('s.startedAt < :to')->setParameter('to', $to, Types::DATETIME_IMMUTABLE);
        }

        /** @var list<Shift> */
        return $qb->getQuery()->getResult();
    }

    public function add(Shift $shift): void
    {
        $this->getEntityManager()->persist($shift);

        try {
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), Shift::ONE_OPEN_SHIFT_PER_USER)) {
                throw $e;
            }

            throw ShiftAlreadyOpenException::forUser($shift->getUserId()->toRfc4122());
        }
    }

    public function save(Shift $shift): void
    {
        $this->getEntityManager()->flush();
    }
}
