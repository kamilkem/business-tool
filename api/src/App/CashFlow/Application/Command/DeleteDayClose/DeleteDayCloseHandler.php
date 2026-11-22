<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\DeleteDayClose;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Application\CashLedgerWriter;
use App\CashFlow\Domain\Event\DayCloseDeleted;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\DayCloseNotFoundException;
use App\CashFlow\Domain\Exception\StorageBalanceNegativeException;
use App\CashFlow\Domain\Repository\DayCloseRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Event\EventPublisherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Deletes an end-of-day close (ADR 0033 §7) with its ledger records, once no deposit storage would
 * go below zero without it. It publishes DayCloseDeleted, from which Income removes the revenue
 * entry booked from it, then CashLedgerChanged: its cash counts in the next close of each storage.
 */
final readonly class DeleteDayCloseHandler implements CommandHandlerInterface
{
    public function __construct(
        private DayCloseRepositoryInterface $closes,
        private CashLedgerWriter $ledger,
        private EventPublisherInterface $events,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException    when the actor is not an active member of the organization
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     * @throws DayCloseNotFoundException        when the close is not one of the organization's
     * @throws StorageBalanceNegativeException  when a deposit storage would go below zero without it
     */
    public function __invoke(DeleteDayCloseCommand $command): void
    {
        $actor = CashFlowActor::managing($this->queryBus, $command->organizationId, $command->actorId);
        $close = (Uuid::isValid($command->dayCloseId) ? $this->closes->findById(Uuid::fromString($command->dayCloseId)) : null)
            ?? throw DayCloseNotFoundException::withId($command->dayCloseId);
        $close->assertCanDelete($actor->id, $actor->role);
        $this->ledger->assertDeletedCloseNeverNegative($close);

        $this->closes->remove($close);

        $actorId = $actor->id->toRfc4122();
        $this->events->publish(new DayCloseDeleted(
            $command->organizationId,
            $close->getId()->toRfc4122(),
            $close->getRegisterId()->toRfc4122(),
            $close->getBusinessDate()->toString(),
            $actorId,
        ));
        $this->ledger->closeChanged($close, $command->organizationId, $actorId);
    }
}
