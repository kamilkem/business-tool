<?php

declare(strict_types=1);

namespace App\Income\Application\Query\ListRevenueEntries;

use App\Income\Application\IncomeActor;
use App\Income\Application\Query\GetRevenueEntry\RevenueEntryView;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\RegisterNotFoundException;
use App\Income\Domain\Exception\RevenuePeriodTooLongException;
use App\Income\Domain\Repository\RegisterRepositoryInterface;
use App\Income\Domain\Repository\RevenueEntryRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\TimeException;
use Symfony\Component\Uid\Uuid;

final readonly class ListRevenueEntriesHandler implements QueryHandlerInterface
{
    public function __construct(
        private RevenueEntryRepositoryInterface $entries,
        private RegisterRepositoryInterface $registers,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @return list<RevenueEntryView>|null by business date, then register name
     *
     * @throws TimeException                      when $from or $to is not a date, or $to is before $from
     * @throws RevenuePeriodTooLongException      when the period is longer than ListRevenueEntriesQuery::MAX_DAYS
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws RegisterNotFoundException          when $registerId is not a register of the organization
     */
    public function __invoke(ListRevenueEntriesQuery $query): ?array
    {
        $period = BusinessPeriod::fromStrings($query->from, $query->to);
        if ($period->dayCount() > ListRevenueEntriesQuery::MAX_DAYS) {
            throw RevenuePeriodTooLongException::of($period, ListRevenueEntriesQuery::MAX_DAYS);
        }

        if (null === IncomeActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        $registerId = null;
        if (null !== $query->registerId) {
            $register = Uuid::isValid($query->registerId) ? $this->registers->findById(Uuid::fromString($query->registerId)) : null;
            $registerId = ($register ?? throw RegisterNotFoundException::withId($query->registerId))->getId();
        }

        return array_map(RevenueEntryView::fromEntry(...), $this->entries->findInPeriod($period, $registerId));
    }
}
