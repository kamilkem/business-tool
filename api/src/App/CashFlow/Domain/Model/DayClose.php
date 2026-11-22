<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

use App\CashFlow\Domain\Exception\DayAlreadyClosedException;
use App\CashFlow\Domain\Exception\DayCloseOutOfOrderException;
use App\CashFlow\Domain\Exception\DenominationNotOfferedException;
use App\CashFlow\Domain\Exception\InvalidDayCloseException;
use App\CashFlow\Domain\Exception\StorageDeactivatedException;
use App\Income\Domain\Model\RevenueEntry;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\Shared\Domain\Time\BusinessDate;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The end-of-day close of one business date (ADR 0033 §6), made by any active member, employees
 * included, and saved straight away: owners and managers review and correct it later (#189).
 *
 * - **One per business date** in the organization, and in date order: a new close's date is after
 *   every existing close's date, so the ledger order matches the order of dates. The unique
 *   constraint below stops two concurrent closes of a date.
 * - **It covers every active storage**: a counted storage gets a full count of every active
 *   denomination of the organization's currency (one not listed is zero), the pieces that stay in it;
 *   a deposit storage gets the pieces put into it, possibly none. It also takes one amount per active
 *   non-cash payment method and one active register (§8).
 * - **It sits in the ledger at `closedAt`** (the server's clock, to the second), with its id as the
 *   tie-break. A counted storage's expected opening is its balance just before that position; the
 *   close keeps it, and the **cash income**: Σ counted (counted value − expected opening) + Σ
 *   deposits. A negative cash income (a shortage, or a withdrawal nobody recorded) is saved and
 *   flagged (isShortage()). Both are recomputed when the ledger before the close changes (#189).
 * - Pieces of a denomination deactivated since the last count are in the expected opening but not in
 *   the count, so they show as a shortage until a manager takes them out with an adjustment.
 *
 * The register and the payment methods are Income's, referred to by id only (ADR 0004). Booking the
 * revenue entry is Income's handler of DayCloseRecorded (#186).
 */
#[ORM\Entity]
#[ORM\Table(name: 'cash_day_close')]
#[ORM\UniqueConstraint(name: self::UNIQUE_DATE, columns: ['organization_id', 'business_date'])]
#[ORM\Index(name: 'cash_day_close_ledger_order', columns: ['closed_at', 'id'])]
class DayClose implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Name of the unique constraint on dates; the repository adapter recognizes its violations. */
    public const string UNIQUE_DATE = 'cash_day_close_date_unique';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'business_date')]
    private BusinessDate $businessDate;

    /** The Income register the close books into. */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $registerId;

    /** ISO 4217: the organization's currency at the close. */
    #[ORM\Column(length: 3)]
    private string $currency;

    /** Minor units; negative for a shortage. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $cashIncome;

    /** The member who closed, of any role. */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $closedBy;

    #[ORM\Column]
    private \DateTimeImmutable $closedAt;

    /** @var Collection<int, DayCloseStorage> */
    #[ORM\OneToMany(targetEntity: DayCloseStorage::class, mappedBy: 'dayClose', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $storages;

    /** @var Collection<int, DayCloseLine> */
    #[ORM\OneToMany(targetEntity: DayCloseLine::class, mappedBy: 'dayClose', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lines;

    /** @var Collection<int, DayClosePayment> */
    #[ORM\OneToMany(targetEntity: DayClosePayment::class, mappedBy: 'dayClose', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $payments;

    private function __construct(Uuid $id, BusinessDate $date, Uuid $registerId, string $currency, Uuid $closedBy, \DateTimeImmutable $closedAt)
    {
        $this->id = $id;
        $this->businessDate = $date;
        $this->registerId = $registerId;
        $this->currency = $currency;
        $this->cashIncome = 0;
        $this->closedBy = $closedBy;
        $this->closedAt = $closedAt;
        $this->storages = new ArrayCollection();
        $this->lines = new ArrayCollection();
        $this->payments = new ArrayCollection();
    }

    /**
     * Records the close of a date. The caller resolves the date (DayCloseRule::dateOfClose()) and,
     * for each counted storage of the offer, its expected opening at positionAt($id, $now).
     *
     * @param list<StorageCount>                 $counts           one per active storage
     * @param list<array{string, int}>           $amounts          (payment method id, minor units), one per active non-cash method
     * @param array<string, int>                 $expectedOpenings by counted storage id: the value, in the offer's currency, of its balance just before the close
     * @param ?ClosedDay                         $sameDate         the existing close of the date, if any
     * @param ?BusinessDate                      $lastClosedDate   the date of the organization's latest close, if any
     *
     * @throws DayAlreadyClosedException       when the date is already closed
     * @throws DayCloseOutOfOrderException     when a later date is already closed
     * @throws InvalidDayCloseException        when the close does not match the offer, or nothing can be closed
     * @throws StorageDeactivatedException     when a storage is deactivated
     * @throws DenominationNotOfferedException when a denomination is in another currency or deactivated
     */
    public static function record(
        Uuid $id,
        BusinessDate $date,
        DayCloseOffer $offer,
        string $registerId,
        array $counts,
        array $amounts,
        array $expectedOpenings,
        ?ClosedDay $sameDate,
        ?BusinessDate $lastClosedDate,
        Uuid $actorId,
        \DateTimeImmutable $now,
    ): self {
        if (null !== $sameDate) {
            throw DayAlreadyClosedException::byMember($date->toString(), $sameDate->dayCloseId->toRfc4122(), $sameDate->closedBy->toRfc4122(), $sameDate->closedByFirstName, $sameDate->closedByLastName, $sameDate->closedAt);
        }
        if (null !== $lastClosedDate && !$lastClosedDate->isBefore($date)) {
            throw DayCloseOutOfOrderException::of($date->toString(), $lastClosedDate->toString());
        }
        if (!$offer->canClose()) {
            throw InvalidDayCloseException::nothingToClose($offer->currency);
        }

        $register = self::uuidOrNull($registerId);
        if (null === $register || !isset($offer->registers[$register->toRfc4122()])) {
            throw InvalidDayCloseException::registerNotOffered($registerId);
        }

        $close = new self($id, $date, $register, $offer->currency, $actorId, self::momentOf($now));
        $close->recordStorages($offer, self::byStorage($offer, $counts), $expectedOpenings);
        $close->recordPayments($offer, $amounts);

        return $close;
    }

    /** Where a close made now with this id sits in the ledger: at the moment, to the second, then by id. */
    public static function positionAt(Uuid $id, \DateTimeImmutable $now): LedgerPosition
    {
        return new LedgerPosition(self::momentOf($now), $id);
    }

    /** What the close does to the storage, or null when it does not cover it. */
    public function ledgerEntryFor(CashStorage $storage): ?LedgerEntry
    {
        $summary = $this->storageSummary($storage);
        if (null === $summary) {
            return null;
        }

        $pieces = [];
        foreach ($this->lines as $line) {
            if ($line->getStorage()->getId()->equals($storage->getId())) {
                $pieces[$line->getDenomination()->getId()->toRfc4122()] = $line->getPieces();
            }
        }

        return CashStorageMode::Counted === $summary->getMode()
            ? LedgerEntry::count($this->position(), $pieces)
            : LedgerEntry::delta($this->position(), $pieces);
    }

    public function position(): LedgerPosition
    {
        return new LedgerPosition($this->closedAt, $this->id);
    }

    /** A negative cash income is flagged for review: a shortage, or a withdrawal nobody recorded (ADR 0033 §6). */
    public function isShortage(): bool
    {
        return $this->cashIncome < 0;
    }

    /** @return array<string, int> minor units by payment method id */
    public function nonCashAmounts(): array
    {
        $amounts = [];
        foreach ($this->payments as $payment) {
            $amounts[$payment->getPaymentMethodId()->toRfc4122()] = $payment->getAmount();
        }

        return $amounts;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBusinessDate(): BusinessDate
    {
        return $this->businessDate;
    }

    public function getRegisterId(): Uuid
    {
        return $this->registerId;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getCashIncome(): int
    {
        return $this->cashIncome;
    }

    public function getClosedBy(): Uuid
    {
        return $this->closedBy;
    }

    public function getClosedAt(): \DateTimeImmutable
    {
        return $this->closedAt;
    }

    /** @return list<DayCloseStorage> in the order the close recorded them */
    public function getStorages(): array
    {
        return array_values($this->storages->toArray());
    }

    /** @return list<DayCloseLine> */
    public function getLines(): array
    {
        return array_values($this->lines->toArray());
    }

    /** @return list<DayClosePayment> */
    public function getPayments(): array
    {
        return array_values($this->payments->toArray());
    }

    /**
     * @param array<string, StorageCount> $counts           by storage id
     * @param array<string, int>          $expectedOpenings by counted storage id
     *
     * @throws InvalidDayCloseException
     * @throws DenominationNotOfferedException
     */
    private function recordStorages(DayCloseOffer $offer, array $counts, array $expectedOpenings): void
    {
        $cashIncome = 0;
        foreach ($offer->storages as $storage) {
            $storageId = $storage->getId()->toRfc4122();
            $count = $counts[$storageId] ?? throw InvalidDayCloseException::missingStorage($storageId, $storage->getName());
            $pieces = self::pieces($storageId, $count->pieces, $offer->currency);

            if (CashStorageMode::Counted === $storage->getMode()) {
                // A full count: every active denomination gets a line, so the count replaces the balance.
                $total = 0;
                foreach ($offer->denominations as $denomination) {
                    $counted = $pieces[$denomination->getId()->toRfc4122()] ?? 0;
                    $this->lines->add(new DayCloseLine($this, $storage, $denomination, $counted));
                    $total += $counted * $denomination->getValue();
                }
                $expectedOpening = $expectedOpenings[$storageId] ?? throw new \LogicException(\sprintf('The expected opening of the counted storage "%s" is needed.', $storageId));
            } else {
                $total = 0;
                foreach ($offer->denominations as $denomination) {
                    $deposited = $pieces[$denomination->getId()->toRfc4122()] ?? 0;
                    if (0 !== $deposited) {
                        $this->lines->add(new DayCloseLine($this, $storage, $denomination, $deposited));
                        $total += $deposited * $denomination->getValue();
                    }
                }
                $expectedOpening = null;
            }

            $summary = new DayCloseStorage($this, $storage, $storage->getMode(), $total, $expectedOpening);
            $this->storages->add($summary);
            $cashIncome += $summary->cashIncome();
        }
        $this->cashIncome = $cashIncome;
    }

    /**
     * @param list<array{string, int}> $amounts
     *
     * @throws InvalidDayCloseException
     */
    private function recordPayments(DayCloseOffer $offer, array $amounts): void
    {
        $byMethod = [];
        foreach ($amounts as [$paymentMethodId, $amount]) {
            $method = self::uuidOrNull($paymentMethodId)?->toRfc4122();
            if (null === $method || !isset($offer->nonCashMethods[$method])) {
                throw InvalidDayCloseException::paymentMethodNotOffered($paymentMethodId);
            }
            if (isset($byMethod[$method])) {
                throw InvalidDayCloseException::duplicatePaymentMethod($method);
            }
            if ($amount < 0 || $amount > RevenueEntry::MAX_AMOUNT) {
                throw InvalidDayCloseException::amount($method, $amount, RevenueEntry::MAX_AMOUNT);
            }
            $byMethod[$method] = $amount;
        }

        foreach ($offer->nonCashMethods as $method => $name) {
            $method = (string) $method;
            $amount = $byMethod[$method] ?? throw InvalidDayCloseException::missingPaymentMethod($method, $name);
            $this->payments->add(new DayClosePayment($this, Uuid::fromString($method), $amount));
        }
    }

    /**
     * @param list<StorageCount> $counts
     *
     * @return array<string, StorageCount> by storage id
     *
     * @throws InvalidDayCloseException    when a storage repeats
     * @throws StorageDeactivatedException when a storage is deactivated
     */
    private static function byStorage(DayCloseOffer $offer, array $counts): array
    {
        $byStorage = [];
        foreach ($counts as $count) {
            $storage = $count->storage;
            $storageId = $storage->getId()->toRfc4122();
            if (isset($byStorage[$storageId])) {
                throw InvalidDayCloseException::duplicateStorage($storageId);
            }
            if (!$storage->isActive()) {
                throw StorageDeactivatedException::of($storageId, $storage->getName());
            }
            $byStorage[$storageId] = $count;
        }

        return $byStorage;
    }

    /**
     * @param list<MovementPieces> $lines
     *
     * @return array<string, int> pieces by denomination id
     *
     * @throws InvalidDayCloseException
     * @throws DenominationNotOfferedException
     */
    private static function pieces(string $storageId, array $lines, string $currency): array
    {
        $pieces = [];
        foreach ($lines as $line) {
            $denomination = $line->denomination;
            $denominationId = $denomination->getId()->toRfc4122();
            if (isset($pieces[$denominationId])) {
                throw InvalidDayCloseException::duplicateDenomination($storageId, $denominationId);
            }
            if ($line->pieces < 0 || $line->pieces > CashMovement::MAX_PIECES) {
                throw InvalidDayCloseException::pieces($storageId, $denominationId, $line->pieces, CashMovement::MAX_PIECES);
            }
            if (strtoupper($currency) !== $denomination->getCurrency()) {
                throw DenominationNotOfferedException::otherCurrency($denominationId, $denomination->getCurrency(), $denomination->getValue(), strtoupper($currency));
            }
            if (!$denomination->isActive()) {
                throw DenominationNotOfferedException::deactivated($denominationId, $denomination->getCurrency(), $denomination->getValue());
            }
            $pieces[$denominationId] = $line->pieces;
        }

        return $pieces;
    }

    private function storageSummary(CashStorage $storage): ?DayCloseStorage
    {
        foreach ($this->storages as $summary) {
            if ($summary->getStorage()->getId()->equals($storage->getId())) {
                return $summary;
            }
        }

        return null;
    }

    /** UTC, to the second, as the column stores it, so the ledger order is the same before and after a reload. */
    private static function momentOf(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));

        return $now->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
    }

    private static function uuidOrNull(string $id): ?Uuid
    {
        return Uuid::isValid($id) ? Uuid::fromString($id) : null;
    }
}
