<?php

declare(strict_types=1);

namespace App\Income\Application\Query\GetRevenueMonth;

use App\Income\Application\IncomeActor;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Repository\RevenueMonthRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\InvalidBusinessPeriodException;
use Psr\Clock\ClockInterface;

final readonly class GetRevenueMonthHandler implements QueryHandlerInterface
{
    public function __construct(
        private RevenueMonthRepositoryInterface $months,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvalidBusinessPeriodException     when the month is not YYYY-MM
     * @throws IncomeManagementForbiddenException when the actor is an employee
     */
    public function __invoke(GetRevenueMonthQuery $query): ?RevenueMonthView
    {
        $period = BusinessPeriod::monthFromString($query->month);
        $actor = IncomeActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId);
        if (null === $actor) {
            return null;
        }

        $month = $this->months->findByMonth($period);

        return new RevenueMonthView(
            $query->month,
            $period->first->toString(),
            $period->last->toString(),
            $period->last->isBefore($actor->today($this->clock)),
            $month?->isClosed() ?? false,
            $month?->getClosedBy()?->toRfc4122(),
            $month?->getClosedAt(),
            null === $month ? [] : array_map(RevenueMonthActionView::of(...), $month->getActions()),
        );
    }
}
