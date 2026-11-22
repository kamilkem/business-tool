<?php

declare(strict_types=1);

namespace App\CashFlow\Infrastructure\Doctrine\Orm\Repository;

use App\CashFlow\Domain\Exception\DenominationInUseException;
use App\CashFlow\Domain\Exception\DenominationValueTakenException;
use App\CashFlow\Domain\Model\CashMovementLine;
use App\CashFlow\Domain\Model\DayCloseLine;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Denomination> */
final class DenominationRepository extends ServiceEntityRepository implements DenominationRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Denomination::class);
    }

    public function findById(Uuid $id): ?Denomination
    {
        return $this->find($id);
    }

    public function findByValue(string $currency, int $value): ?Denomination
    {
        return $this->findOneBy(['currency' => strtoupper($currency), 'value' => $value]);
    }

    public function findByCurrency(string $currency, bool $includeDeactivated = false): array
    {
        $qb = $this->createQueryBuilder('d')
            ->addSelect('CASE WHEN d.deactivatedAt IS NULL THEN 0 ELSE 1 END AS HIDDEN deactivated')
            ->where('d.currency = :currency')
            ->setParameter('currency', strtoupper($currency))
            ->orderBy('deactivated', 'ASC')
            ->addOrderBy('d.value', 'ASC')
            ->addOrderBy('d.id', 'ASC');

        if (!$includeDeactivated) {
            $qb->andWhere('d.deactivatedAt IS NULL');
        }

        /** @var list<Denomination> */
        return $qb->getQuery()->getResult();
    }

    public function findByIds(array $ids): array
    {
        $ids = array_values(array_filter($ids, Uuid::isValid(...)));
        if ([] === $ids) {
            return [];
        }

        /** @var list<Denomination> $found */
        $found = $this->createQueryBuilder('d')
            ->where('d.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (string $id): string => Uuid::fromString($id)->toRfc4122(), $ids))
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($found as $denomination) {
            $byId[$denomination->getId()->toRfc4122()] = $denomination;
        }

        return $byId;
    }

    /** A movement's line or a close refers to it. */
    public function isUsed(Uuid $id): bool
    {
        foreach ([CashMovementLine::class, DayCloseLine::class] as $lines) {
            $used = $this->getEntityManager()->createQueryBuilder()
                ->select('1')
                ->from($lines, 'l')
                ->where('l.denomination = :id')
                ->setParameter('id', $id, UuidType::NAME)
                ->setMaxResults(1)
                ->getQuery()
                ->getArrayResult();
            if ([] !== $used) {
                return true;
            }
        }

        return false;
    }

    public function usedIds(): array
    {
        $ids = [];
        foreach ([CashMovementLine::class, DayCloseLine::class] as $lines) {
            /** @var list<array{id: mixed}> $rows */
            $rows = $this->getEntityManager()->createQueryBuilder()
                ->select('DISTINCT IDENTITY(l.denomination) AS id')
                ->from($lines, 'l')
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $ids[] = $row['id'] instanceof Uuid ? $row['id']->toRfc4122() : (string) $row['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    public function add(Denomination $denomination): void
    {
        $this->getEntityManager()->persist($denomination);

        try {
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), Denomination::UNIQUE_VALUE)) {
                throw $e;
            }

            throw DenominationValueTakenException::of($denomination->getCurrency(), $denomination->getValue());
        }
    }

    public function save(Denomination $denomination): void
    {
        $this->getEntityManager()->flush();
    }

    public function remove(Denomination $denomination): void
    {
        $this->getEntityManager()->remove($denomination);

        try {
            $this->getEntityManager()->flush();
        } catch (ForeignKeyConstraintViolationException) {
            throw DenominationInUseException::of($denomination->getId()->toRfc4122(), $denomination->getCurrency(), $denomination->getValue());
        }
    }
}
