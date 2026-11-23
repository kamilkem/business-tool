<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\ListOrganizationsWithLongShifts;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\TimeTracking\Domain\Model\MaxShiftLength;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;

final readonly class ListOrganizationsWithLongShiftsHandler implements QueryHandlerInterface
{
    public function __construct(private ShiftRepositoryInterface $shifts)
    {
    }

    /** @return list<string> */
    public function __invoke(ListOrganizationsWithLongShiftsQuery $query): array
    {
        $shortest = MaxShiftLength::ofHours(MaxShiftLength::MIN_HOURS);

        return $this->shifts->findOrganizationIdsWithOpenShiftsToReport($shortest->startedBefore(new \DateTimeImmutable($query->now)));
    }
}
