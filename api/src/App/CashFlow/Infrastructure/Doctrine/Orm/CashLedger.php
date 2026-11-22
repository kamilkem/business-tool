<?php

declare(strict_types=1);

namespace App\CashFlow\Infrastructure\Doctrine\Orm;

use App\CashFlow\Domain\CashLedgerInterface;
use App\CashFlow\Domain\Model\CashMovementKind;
use App\CashFlow\Domain\Model\CashMovementLine;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Model\DayCloseLine;
use App\CashFlow\Domain\Model\DayCloseStorage;
use App\CashFlow\Domain\Model\LedgerBalance;
use App\CashFlow\Domain\Model\LedgerEntry;
use App\CashFlow\Domain\Model\LedgerPosition;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The ledger is SQL over two kinds of records (ADR 0033 §4, §6), in DQL so the TenantFilter
 * restricts it to the current organization:
 *
 * - the lines of cash movements, at (occurredAt, id): absolute for an audit count, signed otherwise;
 * - the lines of end-of-day closes, at (closedAt, id): absolute for a storage the close counted
 *   (its DayCloseStorage's mode), added for a deposit.
 *
 * A balance starts from the storage's last count of either kind: its lines are absolute, and every
 * later line is signed, so the sum per denomination of the lines from that count on is the balance.
 */
final readonly class CashLedger implements CashLedgerInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function balanceOf(Uuid $storageId, ?LedgerPosition $before = null): LedgerBalance
    {
        $anchor = $this->lastCountBefore($storageId, $before);

        $pieces = [];
        foreach ([$this->movementLines(), $this->closeLines()] as [$qb, $at, $id]) {
            $qb->select('IDENTITY(l.denomination) AS denominationId', 'SUM(l.pieces) AS pieces')
                ->andWhere('l.storage = :storage')
                ->setParameter('storage', $storageId, UuidType::NAME)
                ->groupBy('l.denomination');
            if (null !== $anchor) {
                self::whereAtOrAfter($qb, $at, $id, $anchor, 'anchor');
            }
            if (null !== $before) {
                self::whereBefore($qb, $at, $id, $before, 'before');
            }

            /** @var list<array{denominationId: mixed, pieces: mixed}> $rows */
            $rows = $qb->getQuery()->getArrayResult();
            foreach ($rows as $row) {
                $denominationId = self::id($row['denominationId']);
                $pieces[$denominationId] = ($pieces[$denominationId] ?? 0) + self::int($row['pieces']);
            }
        }
        $pieces = array_filter($pieces, static fn (int $sum): bool => 0 !== $sum);
        ksort($pieces);

        return new LedgerBalance($pieces, $anchor?->occurredAt);
    }

    public function entriesAfter(Uuid $storageId, LedgerPosition $after): array
    {
        /** @var array<string, array{LedgerPosition, bool, array<string, int>}> $byRecord */
        $byRecord = [];

        [$qb, $at, $id] = $this->movementLines();
        $qb->select('m.id AS recordId', 'm.occurredAt AS at', 'm.kind AS kind', 'IDENTITY(l.denomination) AS denominationId', 'l.pieces AS pieces')
            ->andWhere('l.storage = :storage')
            ->setParameter('storage', $storageId, UuidType::NAME);
        self::whereAfter($qb, $at, $id, $after, 'after');
        /** @var list<array{recordId: mixed, at: mixed, kind: mixed, denominationId: mixed, pieces: mixed}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $key = self::id($row['recordId']);
            $byRecord[$key] ??= [self::position($row['at'], $row['recordId']), self::isCountMovement($row['kind']), []];
            $byRecord[$key][2][self::id($row['denominationId'])] = self::int($row['pieces']);
        }

        [$qb, $at, $id] = $this->closeLines();
        $qb->select('c.id AS recordId', 'c.closedAt AS at', 's.mode AS mode', 'IDENTITY(l.denomination) AS denominationId', 'l.pieces AS pieces')
            ->andWhere('l.storage = :storage')
            ->setParameter('storage', $storageId, UuidType::NAME);
        self::whereAfter($qb, $at, $id, $after, 'after');
        /** @var list<array{recordId: mixed, at: mixed, mode: mixed, denominationId: mixed, pieces: mixed}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $key = self::id($row['recordId']);
            $byRecord[$key] ??= [self::position($row['at'], $row['recordId']), self::isCounted($row['mode']), []];
            $byRecord[$key][2][self::id($row['denominationId'])] = self::int($row['pieces']);
        }

        $entries = array_values(array_map(
            static fn (array $entry): LedgerEntry => $entry[1] ? LedgerEntry::count($entry[0], $entry[2]) : LedgerEntry::delta($entry[0], $entry[2]),
            $byRecord,
        ));
        usort($entries, static fn (LedgerEntry $a, LedgerEntry $b): int => $a->position->isBefore($b->position) ? -1 : ($b->position->isBefore($a->position) ? 1 : 0));

        return $entries;
    }

    /** The position of the storage's last count, by a movement or a close, before the position (null: of all), if it was ever counted. */
    private function lastCountBefore(Uuid $storageId, ?LedgerPosition $before): ?LedgerPosition
    {
        $movement = $this->entityManager->createQueryBuilder()
            ->select('m.id AS id', 'm.occurredAt AS at')
            ->from(CashMovementLine::class, 'l')
            ->join('l.movement', 'm')
            ->where('l.storage = :storage')
            ->andWhere('m.kind = :count')
            ->setParameter('storage', $storageId, UuidType::NAME)
            ->setParameter('count', CashMovementKind::Count->value)
            ->orderBy('m.occurredAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults(1);
        $close = $this->entityManager->createQueryBuilder()
            ->select('c.id AS id', 'c.closedAt AS at')
            ->from(DayCloseStorage::class, 's')
            ->join('s.dayClose', 'c')
            ->where('s.storage = :storage')
            ->andWhere('s.mode = :counted')
            ->setParameter('storage', $storageId, UuidType::NAME)
            ->setParameter('counted', CashStorageMode::Counted->value)
            ->orderBy('c.closedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(1);
        if (null !== $before) {
            self::whereBefore($movement, 'm.occurredAt', 'm.id', $before, 'before');
            self::whereBefore($close, 'c.closedAt', 'c.id', $before, 'before');
        }

        $last = null;
        foreach ([$movement, $close] as $qb) {
            /** @var list<array{id: mixed, at: mixed}> $rows */
            $rows = $qb->getQuery()->getArrayResult();
            if ([] === $rows) {
                continue;
            }
            $position = self::position($rows[0]['at'], $rows[0]['id']);
            if (null === $last || $last->isBefore($position)) {
                $last = $position;
            }
        }

        return $last;
    }

    /** @return array{QueryBuilder, string, string} the movements' lines (l, m), and the fields of their position */
    private function movementLines(): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(CashMovementLine::class, 'l')
            ->join('l.movement', 'm');

        return [$qb, 'm.occurredAt', 'm.id'];
    }

    /** @return array{QueryBuilder, string, string} the closes' lines (l, c, with their storage's summary s), and the fields of their position */
    private function closeLines(): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(DayCloseLine::class, 'l')
            ->join('l.dayClose', 'c')
            ->join(DayCloseStorage::class, 's', 'WITH', 's.dayClose = c AND s.storage = l.storage');

        return [$qb, 'c.closedAt', 'c.id'];
    }

    private static function position(mixed $at, mixed $id): LedgerPosition
    {
        \assert($at instanceof \DateTimeImmutable);

        return new LedgerPosition($at, Uuid::fromString(self::id($id)));
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

    private static function isCountMovement(mixed $kind): bool
    {
        \assert($kind instanceof CashMovementKind || \is_string($kind));

        return CashMovementKind::Count === ($kind instanceof CashMovementKind ? $kind : CashMovementKind::from($kind));
    }

    private static function isCounted(mixed $mode): bool
    {
        \assert($mode instanceof CashStorageMode || \is_string($mode));

        return CashStorageMode::Counted === ($mode instanceof CashStorageMode ? $mode : CashStorageMode::from($mode));
    }

    /** (at, id) < the position, in ledger order. */
    private static function whereBefore(QueryBuilder $qb, string $at, string $id, LedgerPosition $position, string $prefix): void
    {
        $qb->andWhere(\sprintf('(%1$s < :%3$sAt OR (%1$s = :%3$sAt AND %2$s < :%3$sId))', $at, $id, $prefix));
        self::setPosition($qb, $position, $prefix);
    }

    /** (at, id) > the position, in ledger order. */
    private static function whereAfter(QueryBuilder $qb, string $at, string $id, LedgerPosition $position, string $prefix): void
    {
        $qb->andWhere(\sprintf('(%1$s > :%3$sAt OR (%1$s = :%3$sAt AND %2$s > :%3$sId))', $at, $id, $prefix));
        self::setPosition($qb, $position, $prefix);
    }

    /** (at, id) >= the position, in ledger order. */
    private static function whereAtOrAfter(QueryBuilder $qb, string $at, string $id, LedgerPosition $position, string $prefix): void
    {
        $qb->andWhere(\sprintf('(%1$s > :%3$sAt OR (%1$s = :%3$sAt AND %2$s >= :%3$sId))', $at, $id, $prefix));
        self::setPosition($qb, $position, $prefix);
    }

    private static function setPosition(QueryBuilder $qb, LedgerPosition $position, string $prefix): void
    {
        $qb->setParameter($prefix.'At', $position->occurredAt, Types::DATETIME_IMMUTABLE)
            ->setParameter($prefix.'Id', $position->id, UuidType::NAME);
    }
}
