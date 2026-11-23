<?php

declare(strict_types=1);

namespace App\Income\Application\Command\DeleteRevenueEntry;

use App\Income\Application\IncomeActor;
use App\Income\Application\RevenueEntryHistory;
use App\Income\Domain\Exception\RevenueMonthClosedException;
use App\Income\Domain\Repository\RevenueMonthRepositoryInterface;
use Psr\Clock\ClockInterface;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\RevenueEntryBookedByDayCloseException;
use App\Income\Domain\Exception\RevenueEntryNotFoundException;
use App\Income\Domain\Repository\RevenueEntryRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DeleteRevenueEntryHandler implements CommandHandlerInterface
{
    public function __construct(
        private RevenueEntryRepositoryInterface $entries,
        private QueryBusInterface $queryBus,
        private RevenueMonthRepositoryInterface $months,
        private RevenueEntryHistory $history,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException      when the actor is not an active member of the organization
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws RevenueEntryNotFoundException         when the entry is not one of the organization's
     * @throws RevenueMonthClosedException           when the entry's business date is in a closed month
     * @throws RevenueEntryBookedByDayCloseException when the entry is booked from an end-of-day close
     */
    public function __invoke(DeleteRevenueEntryCommand $command): void
    {
        $actor = IncomeActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $entry = Uuid::isValid($command->entryId) ? $this->entries->findById(Uuid::fromString($command->entryId)) : null;
        $entry ??= throw RevenueEntryNotFoundException::withId($command->entryId);
        $this->months->locks()->assertOpen($entry->getBusinessDate());
        $entry->assertCanDelete($actor->id, $actor->role);

        $this->entries->remove($entry);
        $this->history->deleted($entry, null, $actor->id, $this->clock->now());
    }
}
