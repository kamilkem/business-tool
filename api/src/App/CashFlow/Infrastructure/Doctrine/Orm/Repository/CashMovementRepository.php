<?php

declare(strict_types=1);

namespace App\CashFlow\Infrastructure\Doctrine\Orm\Repository;

use App\CashFlow\Domain\Model\CashMovement;
use App\CashFlow\Domain\Model\CashMovementLine;
use App\CashFlow\Domain\Repository\CashMovementRepositoryInterface;
use App\Shared\Domain\Time\UtcRange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Cash movements in DQL, so the TenantFilter restricts them to the current organization. The ledger
 * queries over them and the closes are CashLedger's.
 *
 * @extends ServiceEntityRepository<CashMovement>
 */
final class CashMovementRepository extends ServiceEntityRepository implements CashMovementRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashMovement::class);
    }

    public function findById(Uuid $id): ?CashMovement
    {
        return $this->find($id);
    }

    public function findOccurredIn(UtcRange $range, ?Uuid $storageId = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->addSelect('l')
            ->join('m.lines', 'l')
            ->where('m.occurredAt >= :from AND m.occurredAt < :to')
            ->setParameter('from', $range->from, Types::DATETIME_IMMUTABLE)
            ->setParameter('to', $range->to, Types::DATETIME_IMMUTABLE)
            ->orderBy('m.occurredAt', 'DESC')
            ->addOrderBy('m.id', 'DESC');

        if (null !== $storageId) {
            $qb->andWhere('m.id IN (SELECT IDENTITY(sl.movement) FROM '.CashMovementLine::class.' sl WHERE sl.storage = :storage)')
                ->setParameter('storage', $storageId, UuidType::NAME);
        }

        /** @var list<CashMovement> */
        return $qb->getQuery()->getResult();
    }

    public function add(CashMovement $movement): void
    {
        $this->getEntityManager()->persist($movement);
        $this->getEntityManager()->flush();
    }

    public function remove(CashMovement $movement): void
    {
        $this->getEntityManager()->remove($movement);
        $this->getEntityManager()->flush();
    }
}
