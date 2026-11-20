<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\GetLockedShiftPeriod;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\Exception\TimeException;
use App\TimeTracking\Domain\Repository\LockedShiftPeriodRepositoryInterface;

final readonly class GetLockedShiftPeriodHandler implements QueryHandlerInterface
{
    public function __construct(
        private LockedShiftPeriodRepositoryInterface $periods,
    ) {
    }

    /** @throws TimeException when $date is not a date */
    public function __invoke(GetLockedShiftPeriodQuery $query): ?LockedShiftPeriodView
    {
        $period = $this->periods->findContaining(BusinessDate::fromString($query->date));

        return null === $period ? null : LockedShiftPeriodView::of($period);
    }
}
