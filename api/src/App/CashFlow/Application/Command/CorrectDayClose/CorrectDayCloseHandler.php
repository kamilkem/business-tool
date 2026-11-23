<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\CorrectDayClose;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Application\CashLedgerWriter;
use App\CashFlow\Application\DayCloseBooking;
use App\CashFlow\Application\ExpectedOpenings;
use App\CashFlow\Application\IncomeMonthLock;
use App\CashFlow\Domain\Exception\IncomeMonthClosedException;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\DayCloseNotFoundException;
use App\CashFlow\Domain\Exception\DenominationNotFoundException;
use App\CashFlow\Domain\Exception\DenominationNotOfferedException;
use App\CashFlow\Domain\Exception\InvalidDayCloseException;
use App\CashFlow\Domain\Exception\StorageBalanceNegativeException;
use App\CashFlow\Domain\Exception\StorageNotFoundException;
use App\CashFlow\Domain\Model\StorageCount;
use App\CashFlow\Domain\Repository\DayCloseRepositoryInterface;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use App\Income\Application\Query\GetPaymentMethod\PaymentMethodView;
use App\Income\Application\Query\GetRegister\RegisterView;
use App\Income\Application\Query\ListBookableRegisters\ListBookableRegistersQuery;
use App\Income\Application\Query\ListPaymentMethods\ListPaymentMethodsQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Corrects an end-of-day close (ADR 0033 §7): recomputes its expected openings and cash income from
 * the ledger, checks that no deposit storage goes below zero with it, saves it and publishes
 * DayCloseChanged when what Income books changed, then CashLedgerChanged, from which the next close
 * of each storage is recomputed.
 */
final readonly class CorrectDayCloseHandler implements CommandHandlerInterface
{
    public function __construct(
        private DayCloseRepositoryInterface $closes,
        private DenominationRepositoryInterface $denominations,
        private ExpectedOpenings $openings,
        private CashLedgerWriter $ledger,
        private IncomeMonthLock $incomeMonths,
        private DayCloseBooking $booking,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException    when the actor is not an active member of the organization
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     * @throws DayCloseNotFoundException        when the close is not one of the organization's
     * @throws StorageNotFoundException         when a storage is not one of the organization's
     * @throws DenominationNotFoundException    when a denomination is not one of the organization's
     * @throws DenominationNotOfferedException  when a denomination is in another currency, or deactivated and not in the close
     * @throws InvalidDayCloseException         when the correction does not cover what the close covers
     * @throws IncomeMonthClosedException       when the close is in a closed Income month, or the correction changes the cash income of one that is
     * @throws StorageBalanceNegativeException  when a deposit storage would go below zero
     */
    public function __invoke(CorrectDayCloseCommand $command): void
    {
        $actor = CashFlowActor::managing($this->queryBus, $command->organizationId, $command->actorId);
        $close = (Uuid::isValid($command->dayCloseId) ? $this->closes->findById(Uuid::fromString($command->dayCloseId)) : null)
            ?? throw DayCloseNotFoundException::withId($command->dayCloseId);
        $this->incomeMonths->assertOpen($close->getBusinessDate());

        $counts = array_map(
            fn (array $storage): StorageCount => new StorageCount($this->ledger->storage($storage['storageId']), $this->ledger->pieces($storage['lines'])),
            $command->storages,
        );

        $changed = $close->correct(
            $command->registerId,
            $this->activeRegisters(),
            $this->denominations->findByCurrency($close->getCurrency()),
            $counts,
            array_map(static fn (array $payment): array => [$payment['paymentMethodId'], $payment['amount']], $command->payments),
            $this->methodNames($command->organizationId, $command->actorId),
            $this->openings->at($close->position(), $close->countedStorages(), $close->getCurrency()),
            $actor->id,
            $actor->role,
            $this->clock->now(),
        );
        $this->ledger->assertCorrectedCloseNeverNegative($close);
        $this->closes->save($close);

        if ($changed) {
            $this->booking->changed($close, $command->organizationId, $actor->id->toRfc4122());
        }
        $this->ledger->closeChanged($close, $command->organizationId, $actor->id->toRfc4122());
    }

    /** @return array<string, string> name by id */
    private function activeRegisters(): array
    {
        /** @var list<RegisterView> $registers */
        $registers = $this->queryBus->ask(new ListBookableRegistersQuery());

        return array_column(array_map(static fn (RegisterView $register): array => [$register->id, $register->name], $registers), 1, 0);
    }

    /** @return array<string, string> name by id, deactivated methods included */
    private function methodNames(string $organizationId, string $actorId): array
    {
        /** @var list<PaymentMethodView> $methods */
        $methods = $this->queryBus->ask(new ListPaymentMethodsQuery($organizationId, $actorId, includeDeactivated: true)) ?? [];

        return array_column(array_map(static fn (PaymentMethodView $method): array => [$method->id, $method->name], $methods), 1, 0);
    }
}
