<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\ListLockedShiftPeriods;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\TimeTracking\Application\Query\GetLockedShiftPeriod\LockedShiftPeriodView;
use App\TimeTracking\Domain\Repository\LockedShiftPeriodRepositoryInterface;

final readonly class ListLockedShiftPeriodsHandler implements QueryHandlerInterface
{
    public function __construct(
        private LockedShiftPeriodRepositoryInterface $periods,
    ) {
    }

    /** @return list<LockedShiftPeriodView> oldest first */
    public function __invoke(ListLockedShiftPeriodsQuery $query): array
    {
        return array_map(LockedShiftPeriodView::of(...), $this->periods->findAll());
    }
}
