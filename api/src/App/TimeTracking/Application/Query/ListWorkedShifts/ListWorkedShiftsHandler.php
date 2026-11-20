<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\ListWorkedShifts;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\TimeException;
use App\Shared\Domain\Time\UtcRange;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Model\ShiftChangeRequest;
use App\TimeTracking\Domain\Model\ShiftChangeRequestStatus;
use App\TimeTracking\Domain\Repository\ShiftChangeRequestRepositoryInterface;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ListWorkedShiftsHandler implements QueryHandlerInterface
{
    public function __construct(
        private ShiftRepositoryInterface $shifts,
        private ShiftChangeRequestRepositoryInterface $changeRequests,
    ) {
    }

    /**
     * @throws TimeException when $from or $to is not a date, or $to is before $from
     */
    public function __invoke(ListWorkedShiftsQuery $query): WorkedShiftsView
    {
        $period = BusinessPeriod::fromStrings($query->from, $query->to);
        $timeZone = new \DateTimeZone($query->timeZone);

        if (null !== $query->userId && !Uuid::isValid($query->userId)) {
            return new WorkedShiftsView([], 0);
        }
        $userId = null === $query->userId ? null : Uuid::fromString($query->userId);

        $range = $period->toUtcRange($timeZone);
        $shifts = array_reverse($this->shifts->findStartedIn($range, $userId));

        return new WorkedShiftsView(
            array_map(static fn (Shift $shift): WorkedShiftView => WorkedShiftView::of($shift, $timeZone), $shifts),
            $this->countPendingChangeRequests($range, $userId),
        );
    }

    /**
     * A request for new times counts when the shift's current start or the asked start is in the
     * range, as a correction in a locked period is refused for either (ADR 0027 §8); a missing shift
     * counts when its asked start is.
     */
    private function countPendingChangeRequests(UtcRange $range, ?Uuid $userId): int
    {
        $pending = $this->changeRequests->findAll(ShiftChangeRequestStatus::Pending, $userId);

        $shiftIds = array_values(array_filter(array_map(
            static fn (ShiftChangeRequest $request): ?Uuid => $request->getShiftId(),
            $pending,
        )));
        $startOfShift = [];
        foreach ($this->shifts->findByIds($shiftIds) as $shift) {
            $startOfShift[$shift->getId()->toRfc4122()] = $shift->getStartedAt();
        }

        $count = 0;
        foreach ($pending as $request) {
            $currentStart = $startOfShift[$request->getShiftId()?->toRfc4122() ?? ''] ?? null;
            if ($range->contains($request->getStartedAt()) || (null !== $currentStart && $range->contains($currentStart))) {
                ++$count;
            }
        }

        return $count;
    }
}
