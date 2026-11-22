<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

use App\CashFlow\Domain\Exception\AuditCountNeedsDepositStorageException;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\DenominationNotOfferedException;
use App\CashFlow\Domain\Exception\InvalidMovementException;
use App\CashFlow\Domain\Exception\MovementInTheFutureException;
use App\CashFlow\Domain\Exception\StorageDeactivatedException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One action of an owner or manager on the cash ledger, at `occurredAt` (ADR 0033 §4), with lines
 * of (storage, denomination, pieces). It is one of three kinds (CashMovementKind):
 *
 * - an adjustment: one storage, signed pieces per denomination, never zero;
 * - a transfer: the same pieces out of one storage (negative lines) and into another (positive ones);
 * - a count: an audit count of a deposit storage, absolute pieces of every active denomination of
 *   the organization's currency (one it does not list is zero), which replaces its balance.
 *
 * `occurredAt` is now by default, or an earlier moment (a bank run recorded late), never a later
 * one; it is kept in UTC to the second. Movements are not edited: a wrong one is deleted and entered
 * again. Whether a deposit storage would go below zero is the ledger's rule (StorageLedger), checked
 * by the handlers over the entries the movement changes.
 *
 * Only the organization's current currency is offered. A deactivated denomination or storage can
 * only be emptied: an adjustment that subtracts takes it out, and a transfer can take cash out of a
 * deactivated storage.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cash_movement')]
#[ORM\Index(name: 'cash_movement_ledger_order', columns: ['occurred_at', 'id'])]
class CashMovement implements TenantAwareInterface
{
    use TenantAwareTrait;

    public const int NOTE_MAX_LENGTH = 255;

    /** The most pieces of one denomination a line takes, either way. */
    public const int MAX_PIECES = 1_000_000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 10, enumType: CashMovementKind::class)]
    private CashMovementKind $kind;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: self::NOTE_MAX_LENGTH, nullable: true)]
    private ?string $note;

    /** The owner or manager who entered it. */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, CashMovementLine> */
    #[ORM\OneToMany(targetEntity: CashMovementLine::class, mappedBy: 'movement', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lines;

    /** @param list<array{CashStorage, Denomination, int}> $lines */
    private function __construct(Uuid $id, CashMovementKind $kind, \DateTimeImmutable $occurredAt, ?string $note, Uuid $createdBy, \DateTimeImmutable $createdAt, array $lines)
    {
        $this->id = $id;
        $this->kind = $kind;
        $this->occurredAt = $occurredAt;
        $this->note = $note;
        $this->createdBy = $createdBy;
        $this->createdAt = $createdAt;
        $this->lines = new ArrayCollection();
        foreach ($lines as [$storage, $denomination, $pieces]) {
            $this->lines->add(new CashMovementLine($this, $storage, $denomination, $pieces));
        }
    }

    /**
     * @param list<MovementPieces> $pieces     signed, never zero
     * @param string               $currency   the organization's currency (ISO 4217)
     * @param ?\DateTimeImmutable  $occurredAt null for now
     *
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     * @throws MovementInTheFutureException     when it is set after now
     * @throws InvalidMovementException         when the note is too long, or no line, a repeated denomination, or zero or too many pieces
     * @throws DenominationNotOfferedException  when a denomination is in another currency, or deactivated and not taken out
     * @throws StorageDeactivatedException      when the storage is deactivated and a line adds to it
     */
    public static function adjust(Uuid $id, CashStorage $storage, array $pieces, string $currency, ?string $note, ?\DateTimeImmutable $occurredAt, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): self
    {
        CashFlowAccess::assertCanManage($actorId, $actorRole);
        $occurredAt = self::occurredAt($occurredAt, $now);
        $note = self::normalizeNote($note);
        self::assertLines($pieces, -self::MAX_PIECES, self::MAX_PIECES, allowZero: false);

        $lines = [];
        foreach ($pieces as $line) {
            self::assertInCurrency($line->denomination, $currency);
            if ($line->pieces > 0 && !$line->denomination->isActive()) {
                throw self::deactivated($line->denomination);
            }
            if ($line->pieces > 0 && !$storage->isActive()) {
                throw StorageDeactivatedException::of($storage->getId()->toRfc4122(), $storage->getName());
            }
            $lines[] = [$storage, $line->denomination, $line->pieces];
        }

        return new self($id, CashMovementKind::Adjustment, $occurredAt, $note, $actorId, $now, $lines);
    }

    /**
     * @param list<MovementPieces> $pieces   positive: what leaves $from and enters $to
     * @param string               $currency the organization's currency (ISO 4217)
     *
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     * @throws MovementInTheFutureException     when it is set after now
     * @throws InvalidMovementException         when the storages are the same, the note is too long, or no line, a repeated denomination, or too few or too many pieces
     * @throws DenominationNotOfferedException  when a denomination is in another currency or deactivated
     * @throws StorageDeactivatedException      when $to is deactivated
     */
    public static function transfer(Uuid $id, CashStorage $from, CashStorage $to, array $pieces, string $currency, ?string $note, ?\DateTimeImmutable $occurredAt, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): self
    {
        CashFlowAccess::assertCanManage($actorId, $actorRole);
        if ($from->getId()->equals($to->getId())) {
            throw InvalidMovementException::sameStorage($from->getId()->toRfc4122());
        }
        $occurredAt = self::occurredAt($occurredAt, $now);
        $note = self::normalizeNote($note);
        self::assertLines($pieces, 1, self::MAX_PIECES, allowZero: false);
        if (!$to->isActive()) {
            throw StorageDeactivatedException::of($to->getId()->toRfc4122(), $to->getName());
        }

        $lines = [];
        foreach ($pieces as $line) {
            self::assertOffered($line->denomination, $currency);
            $lines[] = [$from, $line->denomination, -$line->pieces];
            $lines[] = [$to, $line->denomination, $line->pieces];
        }

        return new self($id, CashMovementKind::Transfer, $occurredAt, $note, $actorId, $now, $lines);
    }

    /**
     * @param list<MovementPieces> $pieces   absolute; an offered denomination not listed counts as zero
     * @param list<Denomination>   $offered  the active denominations of the organization's currency
     * @param string               $currency the organization's currency (ISO 4217)
     *
     * @throws CashFlowAccessForbiddenException       when the actor is not an owner or manager
     * @throws AuditCountNeedsDepositStorageException when the storage is counted at each close
     * @throws StorageDeactivatedException            when the storage is deactivated
     * @throws MovementInTheFutureException           when it is set after now
     * @throws InvalidMovementException               when the note is too long, a denomination repeats, the pieces are negative or too many, or nothing is offered
     * @throws DenominationNotOfferedException        when a denomination is in another currency or deactivated
     */
    public static function count(Uuid $id, CashStorage $storage, array $pieces, array $offered, string $currency, ?string $note, ?\DateTimeImmutable $occurredAt, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): self
    {
        CashFlowAccess::assertCanManage($actorId, $actorRole);
        if (CashStorageMode::Deposit !== $storage->getMode()) {
            throw AuditCountNeedsDepositStorageException::of($storage->getId()->toRfc4122(), $storage->getName());
        }
        if (!$storage->isActive()) {
            throw StorageDeactivatedException::of($storage->getId()->toRfc4122(), $storage->getName());
        }
        $occurredAt = self::occurredAt($occurredAt, $now);
        $note = self::normalizeNote($note);
        self::assertLines($pieces, 0, self::MAX_PIECES, allowZero: true, allowNone: true);

        $counted = [];
        foreach ($pieces as $line) {
            self::assertOffered($line->denomination, $currency);
            $counted[$line->denomination->getId()->toRfc4122()] = $line->pieces;
        }

        $lines = [];
        foreach ($offered as $denomination) {
            $lines[] = [$storage, $denomination, $counted[$denomination->getId()->toRfc4122()] ?? 0];
        }
        if ([] === $lines) {
            throw InvalidMovementException::withoutLines();
        }

        return new self($id, CashMovementKind::Count, $occurredAt, $note, $actorId, $now, $lines);
    }

    /**
     * Checks that the movement may be deleted. Whether that would take a deposit storage below zero
     * is the ledger's check (StorageLedger::assertNeverNegative()).
     *
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     */
    public function assertDeletable(Uuid $actorId, MembershipRole $actorRole): void
    {
        CashFlowAccess::assertCanManage($actorId, $actorRole);
    }

    /** What the movement does to the storage, or null when it has no line on it. */
    public function ledgerEntryFor(CashStorage $storage): ?LedgerEntry
    {
        $pieces = [];
        foreach ($this->lines as $line) {
            if ($line->getStorage()->getId()->equals($storage->getId())) {
                $pieces[$line->getDenomination()->getId()->toRfc4122()] = $line->getPieces();
            }
        }
        if ([] === $pieces) {
            return null;
        }

        return CashMovementKind::Count === $this->kind
            ? LedgerEntry::count($this->position(), $pieces)
            : LedgerEntry::delta($this->position(), $pieces);
    }

    /** @return list<CashStorage> the storages it has lines on: one, or two for a transfer (source first) */
    public function storages(): array
    {
        $storages = [];
        foreach ($this->lines as $line) {
            $storage = $line->getStorage();
            $key = $storage->getId()->toRfc4122();
            if (!isset($storages[$key])) {
                $storages[$key] = [$line->getPieces() < 0 ? 0 : 1, $storage];
            }
        }
        usort($storages, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(static fn (array $entry): CashStorage => $entry[1], $storages);
    }

    /** @return list<Denomination> the denominations its lines name, each once */
    public function denominations(): array
    {
        $denominations = [];
        foreach ($this->lines as $line) {
            $denominations[$line->getDenomination()->getId()->toRfc4122()] = $line->getDenomination();
        }

        return array_values($denominations);
    }

    public function position(): LedgerPosition
    {
        return new LedgerPosition($this->occurredAt, $this->id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKind(): CashMovementKind
    {
        return $this->kind;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedBy(): Uuid
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return list<CashMovementLine> */
    public function getLines(): array
    {
        return array_values($this->lines->toArray());
    }

    /** @throws MovementInTheFutureException */
    private static function occurredAt(?\DateTimeImmutable $occurredAt, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        $now = $now->setTimezone($utc);
        $occurredAt = ($occurredAt ?? $now)->setTimezone($utc);
        // Kept to the second, as the column stores it, so the ledger order is the same before and after a reload.
        $occurredAt = $occurredAt->setTime((int) $occurredAt->format('H'), (int) $occurredAt->format('i'), (int) $occurredAt->format('s'));
        if ($occurredAt > $now) {
            throw MovementInTheFutureException::of($occurredAt, $now);
        }

        return $occurredAt;
    }

    /** @throws InvalidMovementException */
    private static function normalizeNote(?string $note): ?string
    {
        $note = null === $note ? null : trim($note);
        if ('' === $note) {
            return null;
        }
        if (null !== $note && mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw InvalidMovementException::note(self::NOTE_MAX_LENGTH);
        }

        return $note;
    }

    /**
     * @param list<MovementPieces> $lines
     *
     * @throws InvalidMovementException
     */
    private static function assertLines(array $lines, int $min, int $max, bool $allowZero, bool $allowNone = false): void
    {
        if ([] === $lines && !$allowNone) {
            throw InvalidMovementException::withoutLines();
        }

        $seen = [];
        foreach ($lines as $line) {
            $denominationId = $line->denomination->getId()->toRfc4122();
            if (isset($seen[$denominationId])) {
                throw InvalidMovementException::duplicateDenomination($denominationId);
            }
            $seen[$denominationId] = true;

            if ($line->pieces < $min || $line->pieces > $max || (0 === $line->pieces && !$allowZero)) {
                throw InvalidMovementException::pieces($denominationId, $line->pieces, $min, $max);
            }
        }
    }

    /** @throws DenominationNotOfferedException */
    private static function assertInCurrency(Denomination $denomination, string $currency): void
    {
        if (strtoupper($currency) !== $denomination->getCurrency()) {
            throw DenominationNotOfferedException::otherCurrency($denomination->getId()->toRfc4122(), $denomination->getCurrency(), $denomination->getValue(), strtoupper($currency));
        }
    }

    /** @throws DenominationNotOfferedException */
    private static function assertOffered(Denomination $denomination, string $currency): void
    {
        self::assertInCurrency($denomination, $currency);
        if (!$denomination->isActive()) {
            throw self::deactivated($denomination);
        }
    }

    private static function deactivated(Denomination $denomination): DenominationNotOfferedException
    {
        return DenominationNotOfferedException::deactivated($denomination->getId()->toRfc4122(), $denomination->getCurrency(), $denomination->getValue());
    }
}
