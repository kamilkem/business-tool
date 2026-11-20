<?php

declare(strict_types=1);

namespace App\Payroll\Application;

use App\Payroll\Domain\Model\LockedPayrollDates;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Time\BusinessPeriod;
use App\TimeTracking\Application\Query\GetLockedShiftPeriod\LockedShiftPeriodView;
use App\TimeTracking\Application\Query\ListLockedShiftPeriods\ListLockedShiftPeriodsQuery;

/**
 * The current organization's locked periods, which Time tracking owns (ADR 0027 §8), for the rate
 * writes. The organization must be the tenant context's (as in a request or an organization job).
 */
final readonly class LockedPayrollDatesLoader
{
    public function __construct(
        private QueryBusInterface $queryBus,
    ) {
    }

    public function load(): LockedPayrollDates
    {
        /** @var list<LockedShiftPeriodView> $periods */
        $periods = $this->queryBus->ask(new ListLockedShiftPeriodsQuery());

        return LockedPayrollDates::of(array_map(
            static fn (LockedShiftPeriodView $period): BusinessPeriod => BusinessPeriod::fromStrings($period->firstDate, $period->lastDate),
            $periods,
        ));
    }
}
