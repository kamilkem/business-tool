<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\DayAlreadyClosedException;
use App\CashFlow\Domain\Exception\DayCloseOutOfOrderException;
use App\CashFlow\Domain\Exception\DenominationNotOfferedException;
use App\CashFlow\Domain\Exception\InvalidDayCloseException;
use App\CashFlow\Domain\Exception\StorageDeactivatedException;
use App\Income\Domain\Model\RevenueEntry;
use App\Organization\Domain\Model\MembershipRole;
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
 * revenue entry is Income's handler of DayCloseRecorded and DayCloseChanged; DayCloseDeleted removes it.
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

    /** The owner or manager who corrected the close last (#189); null while nobody did. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $updatedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

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
     * @throws InvalidDayCloseException        when the close does not match the offer, nothing can be closed or
     *                                         booked, or the cash income is more than a revenue line takes
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
        if (!$offer->canBook()) {
            throw InvalidDayCloseException::nothingToBook();
        }

        $register = self::uuidOrNull($registerId);
        if (null === $register || !isset($offer->registers[$register->toRfc4122()])) {
            throw InvalidDayCloseException::registerNotOffered($registerId);
        }

        $close = new self($id, $date, $register, $offer->currency, $actorId, self::momentOf($now));
        $close->recordStorages($offer, self::byStorage($counts), $expectedOpenings);
        $close->assertBookableCashIncome();
        $close->recordPayments($offer, $amounts);

        return $close;
    }

    /**
     * An owner or manager corrects the close (ADR 0033 §7): its counts, deposits, non-cash amounts
     * and register. The business date never changes (delete and redo the close instead), and it
     * covers what it covered when it was made: the same storages, in the modes they had then, and the
     * same non-cash payment methods. A storage, denomination or method deactivated since may still be
     * corrected; a denomination is offered when it is active or the close already counted it. The
     * register is the close's own or an active one. The caller computes each counted storage's
     * expected opening at position() again, so the correction also recomputes them.
     *
     * @param array<string, string>              $registers        active registers: name by id
     * @param list<Denomination>                 $denominations    the active denominations of the close's currency
     * @param list<StorageCount>                 $counts           one per storage of the close
     * @param list<array{string, int}>           $amounts          (payment method id, minor units), one per non-cash method of the close
     * @param array<string, string>              $methodNames      the payment methods' names by id, for the errors
     * @param array<string, int>                 $expectedOpenings by counted storage id of the close
     *
     * @return bool whether what Income books changed: the register, a non-cash amount or the cash income
     *
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     * @throws InvalidDayCloseException         when the correction does not match the close, or the cash income is more than a revenue line takes
     * @throws DenominationNotOfferedException  when a denomination is in another currency, or deactivated and not in the close
     */
    public function correct(
        string $registerId,
        array $registers,
        array $denominations,
        array $counts,
        array $amounts,
        array $methodNames,
        array $expectedOpenings,
        Uuid $actorId,
        MembershipRole $actorRole,
        \DateTimeImmutable $now,
    ): bool {
        CashFlowAccess::assertCanCorrectDayCloses($actorId, $actorRole);
        $before = $this->booking();

        $register = self::uuidOrNull($registerId);
        if (null === $register || (!$register->equals($this->registerId) && !isset($registers[$register->toRfc4122()]))) {
            throw InvalidDayCloseException::registerNotOffered($registerId);
        }

        // Everything is checked before anything changes.
        $byStorage = self::byStorage($counts, active: false);
        foreach ($byStorage as $storageId => $count) {
            if (null === $this->storageSummary($count->storage)) {
                throw InvalidDayCloseException::storageNotInClose((string) $storageId, $count->storage->getName());
            }
        }
        $counted = $this->countedDenominations();
        $pieces = [];
        foreach ($this->getStorages() as $summary) {
            $storage = $summary->getStorage();
            $storageId = $storage->getId()->toRfc4122();
            $count = $byStorage[$storageId] ?? throw InvalidDayCloseException::missingStorage($storageId, $storage->getName());
            $pieces[$storageId] = self::pieces($storageId, $count->pieces, $this->currency, $counted);
        }
        $payments = $this->correctedPayments($amounts, $methodNames);
        $offered = $this->offered($denominations);

        $cashIncome = 0;
        foreach ($this->getStorages() as $summary) {
            $storage = $summary->getStorage();
            $storageId = $storage->getId()->toRfc4122();
            $expectedOpening = CashStorageMode::Counted === $summary->getMode()
                ? $expectedOpenings[$storageId] ?? throw new \LogicException(\sprintf('The expected opening of the counted storage "%s" is needed.', $storageId))
                : null;
            $cashIncome += $this->applyStorage($storage, $summary->getMode(), $pieces[$storageId], $offered, $expectedOpening)->cashIncome();
        }
        $this->cashIncome = $cashIncome;
        $this->assertBookableCashIncome();

        foreach ($this->payments as $payment) {
            $payment->changeAmount($payments[$payment->getPaymentMethodId()->toRfc4122()]);
        }
        $this->registerId = $register;
        $this->updatedBy = $actorId;
        $this->updatedAt = self::momentOf($now);

        return $before !== $this->booking();
    }

    /**
     * The ledger before the close changed (ADR 0033 §7): a backdated movement, or a corrected or
     * deleted earlier close. The counts stay; each counted storage's expected opening, and with them
     * the cash income, are recomputed. It records no corrector: nobody corrected this close. A cash
     * income above what a revenue line takes is kept, flagged by Income's refusal to book it.
     *
     * @param array<string, int> $expectedOpenings by counted storage id of the close
     *
     * @return bool whether the cash income changed
     */
    public function recalculate(array $expectedOpenings): bool
    {
        $cashIncome = 0;
        foreach ($this->storages as $summary) {
            if (CashStorageMode::Counted === $summary->getMode()) {
                $storageId = $summary->getStorage()->getId()->toRfc4122();
                $summary->recalculate($expectedOpenings[$storageId] ?? throw new \LogicException(\sprintf('The expected opening of the counted storage "%s" is needed.', $storageId)));
            }
            $cashIncome += $summary->cashIncome();
        }

        $changed = $cashIncome !== $this->cashIncome;
        $this->cashIncome = $cashIncome;

        return $changed;
    }

    /**
     * Owners and managers delete a close (ADR 0033 §7), to redo it with another date, or because it
     * should not exist. Its cash then counts in the next close of each storage.
     *
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     */
    public function assertCanDelete(Uuid $actorId, MembershipRole $actorRole): void
    {
        CashFlowAccess::assertCanCorrectDayCloses($actorId, $actorRole);
    }

    /** @return list<CashStorage> the storages the close covers */
    public function coveredStorages(): array
    {
        return array_map(static fn (DayCloseStorage $summary): CashStorage => $summary->getStorage(), $this->getStorages());
    }

    /** @return list<CashStorage> the storages the close counted: the ones whose expected opening comes from the ledger */
    public function countedStorages(): array
    {
        return array_values(array_map(
            static fn (DayCloseStorage $summary): CashStorage => $summary->getStorage(),
            array_filter($this->getStorages(), static fn (DayCloseStorage $summary): bool => CashStorageMode::Counted === $summary->getMode()),
        ));
    }

    /** @return list<Denomination> every denomination the close has a line of */
    public function denominations(): array
    {
        $denominations = [];
        foreach ($this->lines as $line) {
            $denominations[$line->getDenomination()->getId()->toRfc4122()] = $line->getDenomination();
        }

        return array_values($denominations);
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

    public function getUpdatedBy(): ?Uuid
    {
        return $this->updatedBy;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
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
            $pieces = self::pieces($storageId, $count->pieces, $offer->currency, []);
            $expectedOpening = CashStorageMode::Counted === $storage->getMode()
                ? $expectedOpenings[$storageId] ?? throw new \LogicException(\sprintf('The expected opening of the counted storage "%s" is needed.', $storageId))
                : null;
            $cashIncome += $this->applyStorage($storage, $storage->getMode(), $pieces, $offer->denominations, $expectedOpening)->cashIncome();
        }
        $this->cashIncome = $cashIncome;
    }

    /**
     * Sets what the close did to one storage, keeping the rows that stay (their keys are the close,
     * the storage and the denomination, so a row removed and added again in one flush would clash).
     * A counted storage gets a line per offered denomination, zeros included: the count replaces the
     * balance. A deposit storage gets the non-zero ones.
     *
     * @param array<string, int> $pieces        by denomination id
     * @param list<Denomination> $denominations offered
     */
    private function applyStorage(CashStorage $storage, CashStorageMode $mode, array $pieces, array $denominations, ?int $expectedOpening): DayCloseStorage
    {
        $wanted = [];
        $total = 0;
        foreach ($denominations as $denomination) {
            $count = $pieces[$denomination->getId()->toRfc4122()] ?? 0;
            if (CashStorageMode::Counted === $mode || 0 !== $count) {
                $wanted[$denomination->getId()->toRfc4122()] = [$denomination, $count];
                $total += $count * $denomination->getValue();
            }
        }

        foreach ($this->lines->toArray() as $line) {
            if (!$line->getStorage()->getId()->equals($storage->getId())) {
                continue;
            }
            $denominationId = $line->getDenomination()->getId()->toRfc4122();
            if (isset($wanted[$denominationId])) {
                $line->changePieces($wanted[$denominationId][1]);
                unset($wanted[$denominationId]);
            } else {
                $this->lines->removeElement($line);
            }
        }
        foreach ($wanted as [$denomination, $count]) {
            $this->lines->add(new DayCloseLine($this, $storage, $denomination, $count));
        }

        $summary = $this->storageSummary($storage);
        if (null === $summary) {
            $summary = new DayCloseStorage($this, $storage, $mode, $total, $expectedOpening);
            $this->storages->add($summary);
        } else {
            $summary->change($total, $expectedOpening);
        }

        return $summary;
    }

    /**
     * @param list<array{string, int}> $amounts
     * @param array<string, string>    $methodNames by id
     *
     * @return array<string, int> the corrected amount of each of the close's methods, by id
     *
     * @throws InvalidDayCloseException
     */
    private function correctedPayments(array $amounts, array $methodNames): array
    {
        $current = $this->nonCashAmounts();

        $byMethod = [];
        foreach ($amounts as [$paymentMethodId, $amount]) {
            $method = self::uuidOrNull($paymentMethodId)?->toRfc4122();
            if (null === $method || !isset($current[$method])) {
                throw InvalidDayCloseException::paymentMethodNotInClose($paymentMethodId);
            }
            if (isset($byMethod[$method])) {
                throw InvalidDayCloseException::duplicatePaymentMethod($method);
            }
            if ($amount < 0 || $amount > RevenueEntry::MAX_AMOUNT) {
                throw InvalidDayCloseException::amount($method, $amount, RevenueEntry::MAX_AMOUNT);
            }
            $byMethod[$method] = $amount;
        }
        foreach (array_keys($current) as $method) {
            $method = (string) $method;
            if (!isset($byMethod[$method])) {
                throw InvalidDayCloseException::missingPaymentMethod($method, $methodNames[$method] ?? '');
            }
        }

        return $byMethod;
    }

    /** Income books the cash income as one payment line (ADR 0033 §9). */
    private function assertBookableCashIncome(): void
    {
        if ($this->cashIncome > RevenueEntry::MAX_AMOUNT) {
            throw InvalidDayCloseException::cashIncome($this->cashIncome, RevenueEntry::MAX_AMOUNT);
        }
    }

    /** What Income books from the close: a change publishes DayCloseChanged (ADR 0033 §7). */
    private function booking(): string
    {
        $amounts = $this->nonCashAmounts();
        ksort($amounts);

        return json_encode([$this->registerId->toRfc4122(), $this->cashIncome, $amounts], \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, true> the ids of the denominations the close has a line of */
    private function countedDenominations(): array
    {
        $ids = [];
        foreach ($this->lines as $line) {
            $ids[$line->getDenomination()->getId()->toRfc4122()] = true;
        }

        return $ids;
    }

    /**
     * What a correction of a storage offers: the active denominations, then those the close already
     * has a line of, in this order and once each.
     *
     * @param list<Denomination> $active
     *
     * @return list<Denomination>
     */
    private function offered(array $active): array
    {
        $offered = [];
        foreach ($active as $denomination) {
            $offered[$denomination->getId()->toRfc4122()] = $denomination;
        }
        foreach ($this->lines as $line) {
            $offered[$line->getDenomination()->getId()->toRfc4122()] ??= $line->getDenomination();
        }
        return array_values($offered);
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
     * @throws StorageDeactivatedException when a storage is deactivated and $active
     */
    private static function byStorage(array $counts, bool $active = true): array
    {
        $byStorage = [];
        foreach ($counts as $count) {
            $storage = $count->storage;
            $storageId = $storage->getId()->toRfc4122();
            if (isset($byStorage[$storageId])) {
                throw InvalidDayCloseException::duplicateStorage($storageId);
            }
            if ($active && !$storage->isActive()) {
                throw StorageDeactivatedException::of($storageId, $storage->getName());
            }
            $byStorage[$storageId] = $count;
        }

        return $byStorage;
    }

    /**
     * @param list<MovementPieces> $lines
     * @param array<string, true>  $alsoOffered ids of deactivated denominations that may still be counted: those the close already has
     *
     * @return array<string, int> pieces by denomination id
     *
     * @throws InvalidDayCloseException
     * @throws DenominationNotOfferedException
     */
    private static function pieces(string $storageId, array $lines, string $currency, array $alsoOffered): array
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
            if (!$denomination->isActive() && !isset($alsoOffered[$denominationId])) {
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
