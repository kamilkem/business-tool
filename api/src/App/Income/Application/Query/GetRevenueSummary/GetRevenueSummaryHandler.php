<?php

declare(strict_types=1);

namespace App\Income\Application\Query\GetRevenueSummary;

use App\Income\Application\IncomeActor;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\RevenuePeriodTooLongException;
use App\Income\Domain\Repository\RevenueEntryRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\TimeException;

/**
 * Adds up the period's entries in PHP rather than in SQL: a period holds one entry per register and
 * day (at most a few thousand in a year), findInPeriod() already reads them with their lines under
 * the TenantFilter, and the sums stay next to the totals of a single entry (RevenueEntryView).
 */
final readonly class GetRevenueSummaryHandler implements QueryHandlerInterface
{
    public function __construct(
        private RevenueEntryRepositoryInterface $entries,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws TimeException                      when $from or $to is not a date, or $to is before $from
     * @throws RevenuePeriodTooLongException      when the period is longer than GetRevenueSummaryQuery::MAX_DAYS
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     */
    public function __invoke(GetRevenueSummaryQuery $query): ?RevenueSummaryView
    {
        $period = BusinessPeriod::fromStrings($query->from, $query->to);
        if ($period->dayCount() > GetRevenueSummaryQuery::MAX_DAYS) {
            throw RevenuePeriodTooLongException::of($period, GetRevenueSummaryQuery::MAX_DAYS);
        }

        $actor = IncomeActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId);
        if (null === $actor) {
            return null;
        }

        return RevenueSummaryView::of($period, $actor->organization->currency, $this->entries->findInPeriod($period));
    }
}
