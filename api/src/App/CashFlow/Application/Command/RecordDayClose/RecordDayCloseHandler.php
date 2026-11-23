<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\RecordDayClose;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Application\CashLedgerWriter;
use App\CashFlow\Application\DayCloseOffers;
use App\CashFlow\Application\DayCloseRules;
use App\CashFlow\Application\ExpectedOpenings;
use App\CashFlow\Application\IncomeMonthLock;
use App\CashFlow\Domain\Exception\IncomeMonthClosedException;
use App\CashFlow\Domain\Event\DayCloseRecorded;
use App\CashFlow\Domain\Exception\DayAlreadyClosedException;
use App\CashFlow\Domain\Exception\DayCloseOutOfOrderException;
use App\CashFlow\Domain\Exception\DenominationNotFoundException;
use App\CashFlow\Domain\Exception\DenominationNotOfferedException;
use App\CashFlow\Domain\Exception\InvalidDayCloseDateException;
use App\CashFlow\Domain\Exception\InvalidDayCloseException;
use App\CashFlow\Domain\Exception\StorageDeactivatedException;
use App\CashFlow\Domain\Exception\StorageNotFoundException;
use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Model\ClosedDay;
use App\CashFlow\Domain\Model\DayClose;
use App\CashFlow\Domain\Model\StorageCount;
use App\CashFlow\Domain\Repository\DayCloseRepositoryInterface;
use App\Organization\Application\Query\GetMemberName\GetMemberNameQuery;
use App\Organization\Application\Query\GetMemberName\MemberNameView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Event\EventPublisherInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\Exception\TimeException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Records an end-of-day close (ADR 0033 §6): resolves its date from the organization's setting,
 * finds what it covers (DayCloseOffers), computes each counted storage's expected opening from the
 * ledger just before the close, saves it and publishes DayCloseRecorded, which Income books (#186).
 *
 * Two closes of the same date at the same moment are stopped by the unique constraint (409). A
 * movement recorded between reading the ledger and saving the close is not in its expected opening;
 * the buses run without transactions (ADR 0004); that movement's CashLedgerChanged recomputes the
 * close (RecalculateDayCloseHandler).
 */
final readonly class RecordDayCloseHandler implements CommandHandlerInterface
{
    public function __construct(
        private DayCloseRepositoryInterface $closes,
        private ExpectedOpenings $openings,
        private CashLedgerWriter $lookup,
        private DayCloseOffers $offers,
        private DayCloseRules $rules,
        private IncomeMonthLock $incomeMonths,
        private EventPublisherInterface $events,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException   when the actor is not an active member of the organization
     * @throws TimeException                   when the date is not a Y-m-d date
     * @throws InvalidDayCloseDateException    when the date is not one the actor may close
     * @throws IncomeMonthClosedException       when the date is in a closed Income month
     * @throws DayAlreadyClosedException       when the date is already closed
     * @throws DayCloseOutOfOrderException     when a later date is already closed
     * @throws StorageNotFoundException        when a storage is not one of the organization's
     * @throws DenominationNotFoundException   when a denomination is not one of the organization's
     * @throws StorageDeactivatedException     when a storage is deactivated
     * @throws DenominationNotOfferedException when a denomination is in another currency or deactivated
     * @throws InvalidDayCloseException        when the close does not cover what it should, or nothing can be closed
     */
    public function __invoke(RecordDayCloseCommand $command): void
    {
        $actor = CashFlowActor::memberOrNull($this->queryBus, $command->organizationId, $command->actorId)
            ?? throw OrganizationNotFoundException::forId($command->organizationId);
        $now = $this->clock->now();
        $date = $this->rules->current()->dateOfClose(
            null === $command->date ? null : BusinessDate::fromString($command->date),
            $actor->role,
            $now,
            new \DateTimeZone($actor->organization->timezone),
        );

        $this->incomeMonths->assertOpen($date);

        $id = Uuid::fromString($command->dayCloseId);
        $offer = $this->offers->current($actor);
        $counts = array_map(
            fn (array $storage): StorageCount => new StorageCount($this->lookup->storage($storage['storageId']), $this->lookup->pieces($storage['lines'])),
            $command->storages,
        );

        $close = DayClose::record(
            $id,
            $date,
            $offer,
            $command->registerId,
            $counts,
            array_map(static fn (array $payment): array => [$payment['paymentMethodId'], $payment['amount']], $command->payments),
            $this->openings->at(
                DayClose::positionAt($id, $now),
                array_values(array_filter($offer->storages, static fn (CashStorage $storage): bool => CashStorageMode::Counted === $storage->getMode())),
                $offer->currency,
            ),
            $this->closedDay($date, $command->organizationId, $command->actorId),
            $this->closes->findLatest()?->getBusinessDate(),
            $actor->id,
            $now,
        );
        $this->closes->add($close);

        $this->events->publish(new DayCloseRecorded(
            $command->organizationId,
            $close->getId()->toRfc4122(),
            $close->getRegisterId()->toRfc4122(),
            $close->getBusinessDate()->toString(),
            $close->getCurrency(),
            $close->getCashIncome(),
            $close->nonCashAmounts(),
            $actor->id->toRfc4122(),
        ));
    }

    private function closedDay(BusinessDate $date, string $organizationId, string $actorId): ?ClosedDay
    {
        $existing = $this->closes->findByDate($date);
        if (null === $existing) {
            return null;
        }

        /** @var MemberNameView|null $name */
        $name = $this->queryBus->ask(new GetMemberNameQuery($organizationId, $actorId, $existing->getClosedBy()->toRfc4122()));

        return new ClosedDay(
            $existing->getId(),
            $existing->getBusinessDate(),
            $existing->getClosedBy(),
            $name->firstName ?? '',
            $name->lastName ?? '',
            $existing->getClosedAt(),
        );
    }
}
