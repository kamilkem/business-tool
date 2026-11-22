<?php

declare(strict_types=1);

namespace App\CashFlow\Infrastructure\Doctrine\Orm\Repository;

use App\CashFlow\Domain\Model\CashMovement;
use App\CashFlow\Domain\Model\CashMovementKind;
use App\CashFlow\Domain\Model\CashMovementLine;
use App\CashFlow\Domain\Model\LedgerBalance;
use App\CashFlow\Domain\Model\LedgerEntry;
use App\CashFlow\Domain\Model\LedgerPosition;
use App\CashFlow\Domain\Repository\CashMovementRepositoryInterface;
use App\Shared\Domain\Time\UtcRange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The ledger is SQL over the movements' lines (ADR 0033 §4), in DQL so the TenantFilter restricts
 * it to the current organization. A balance starts from the storage's last count: its lines are
 * absolute, and every later line is signed, so their sum per denomination is the balance.
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

    public function balanceOf(Uuid $storageId, ?LedgerPosition $before = null): LedgerBalance
    {
        $anchor = $this->lastCountBefore($storageId, $before);

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(l.denomination) AS denominationId', 'SUM(l.pieces) AS pieces')
            ->from(CashMovementLine::class, 'l')
            ->join('l.movement', 'm')
            ->where('l.storage = :storage')
            ->setParameter('storage', $storageId, UuidType::NAME)
            ->groupBy('l.denomination');
        if (null !== $anchor) {
            self::whereAtOrAfter($qb, $anchor, 'anchor');
        }
        if (null !== $before) {
            self::whereBefore($qb, $before, 'before');
        }

        /** @var list<array{denominationId: mixed, pieces: mixed}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        $pieces = [];
        foreach ($rows as $row) {
            $sum = self::int($row['pieces']);
            if (0 !== $sum) {
                $pieces[self::id($row['denominationId'])] = $sum;
            }
        }
        ksort($pieces);

        return new LedgerBalance($pieces, $anchor?->occurredAt);
    }

    public function entriesAfter(Uuid $storageId, LedgerPosition $after): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('m.id AS movementId', 'm.occurredAt AS occurredAt', 'm.kind AS kind', 'IDENTITY(l.denomination) AS denominationId', 'l.pieces AS pieces')
            ->from(CashMovementLine::class, 'l')
            ->join('l.movement', 'm')
            ->where('l.storage = :storage')
            ->setParameter('storage', $storageId, UuidType::NAME)
            ->orderBy('m.occurredAt', 'ASC')
            ->addOrderBy('m.id', 'ASC');
        self::whereAfter($qb, $after, 'after');

        /** @var list<array{movementId: mixed, occurredAt: mixed, kind: mixed, denominationId: mixed, pieces: mixed}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        /** @var array<string, array{LedgerPosition, bool, array<string, int>}> $byMovement */
        $byMovement = [];
        foreach ($rows as $row) {
            $key = self::id($row['movementId']);
            $byMovement[$key] ??= [self::position($row['occurredAt'], $row['movementId']), self::isCount($row['kind']), []];
            $byMovement[$key][2][self::id($row['denominationId'])] = self::int($row['pieces']);
        }

        return array_values(array_map(
            static fn (array $entry): LedgerEntry => $entry[1] ? LedgerEntry::count($entry[0], $entry[2]) : LedgerEntry::delta($entry[0], $entry[2]),
            $byMovement,
        ));
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

    /** The position of the storage's last count before the position (null: of all), if it was ever counted. */
    private function lastCountBefore(Uuid $storageId, ?LedgerPosition $before): ?LedgerPosition
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('m.id AS id', 'm.occurredAt AS occurredAt')
            ->from(CashMovementLine::class, 'l')
            ->join('l.movement', 'm')
            ->where('l.storage = :storage')
            ->andWhere('m.kind = :count')
            ->setParameter('storage', $storageId, UuidType::NAME)
            ->setParameter('count', CashMovementKind::Count->value)
            ->orderBy('m.occurredAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults(1);
        if (null !== $before) {
            self::whereBefore($qb, $before, 'before');
        }

        /** @var list<array{id: mixed, occurredAt: mixed}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return [] === $rows ? null : self::position($rows[0]['occurredAt'], $rows[0]['id']);
    }

    private static function position(mixed $occurredAt, mixed $id): LedgerPosition
    {
        \assert($occurredAt instanceof \DateTimeImmutable);

        return new LedgerPosition($occurredAt, Uuid::fromString(self::id($id)));
    }

    /** A UUID from a scalar result: converted by its type (a field) or not (IDENTITY()). */
    private static function id(mixed $id): string
    {
        if ($id instanceof Uuid) {
            return $id->toRfc4122();
        }
        \assert(\is_string($id));

        return Uuid::fromString($id)->toRfc4122();
    }

    private static function int(mixed $value): int
    {
        \assert(\is_int($value) || \is_string($value));

        return (int) $value;
    }

    private static function isCount(mixed $kind): bool
    {
        \assert($kind instanceof CashMovementKind || \is_string($kind));

        return CashMovementKind::Count === ($kind instanceof CashMovementKind ? $kind : CashMovementKind::from($kind));
    }

    /** (m.occurredAt, m.id) < the position, in ledger order. */
    private static function whereBefore(QueryBuilder $qb, LedgerPosition $position, string $prefix): void
    {
        $qb->andWhere(\sprintf('(m.occurredAt < :%1$sAt OR (m.occurredAt = :%1$sAt AND m.id < :%1$sId))', $prefix));
        self::setPosition($qb, $position, $prefix);
    }

    /** (m.occurredAt, m.id) > the position, in ledger order. */
    private static function whereAfter(QueryBuilder $qb, LedgerPosition $position, string $prefix): void
    {
        $qb->andWhere(\sprintf('(m.occurredAt > :%1$sAt OR (m.occurredAt = :%1$sAt AND m.id > :%1$sId))', $prefix));
        self::setPosition($qb, $position, $prefix);
    }

    /** (m.occurredAt, m.id) >= the position, in ledger order. */
    private static function whereAtOrAfter(QueryBuilder $qb, LedgerPosition $position, string $prefix): void
    {
        $qb->andWhere(\sprintf('(m.occurredAt > :%1$sAt OR (m.occurredAt = :%1$sAt AND m.id >= :%1$sId))', $prefix));
        self::setPosition($qb, $position, $prefix);
    }

    private static function setPosition(QueryBuilder $qb, LedgerPosition $position, string $prefix): void
    {
        $qb->setParameter($prefix.'At', $position->occurredAt, Types::DATETIME_IMMUTABLE)
            ->setParameter($prefix.'Id', $position->id, UuidType::NAME);
    }
}
