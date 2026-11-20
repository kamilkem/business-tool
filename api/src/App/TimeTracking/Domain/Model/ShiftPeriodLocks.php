<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Model;

use App\Shared\Domain\Time\BusinessDate;
use App\TimeTracking\Domain\Exception\ShiftPeriodLockedException;

/**
 * The organization's locked shift periods with its time zone, as the shift writes check them
 * (ADR 0027 §8): entering a shift (Shift::record()), correcting one (Shift::correct(), old and new
 * start), and submitting a shift change request about one (ShiftChangeRequest). Approving a request
 * goes through Shift::record() or Shift::correct(), so it is refused the same way and the request
 * stays pending. Deleting a shift (#28) has to call assertUnlocked() with the shift's start too.
 *
 * Clocking in and out are not checked: a period is locked only once it is over and has no open
 * shifts (ADR 0027 §7), so the server's now is never in it.
 */
final readonly class ShiftPeriodLocks
{
    /** @param list<LockedShiftPeriod> $periods */
    private function __construct(
        private array $periods,
        private \DateTimeZone $timeZone,
    ) {
    }

    /**
     * @param list<LockedShiftPeriod> $periods  the organization's locked periods
     *                                          (LockedShiftPeriodRepositoryInterface::findAll())
     * @param \DateTimeZone           $timeZone the organization's
     */
    public static function of(array $periods, \DateTimeZone $timeZone): self
    {
        return new self($periods, $timeZone);
    }

    /** Nothing is locked. */
    public static function none(): self
    {
        return new self([], new \DateTimeZone('UTC'));
    }

    /**
     * @param \DateTimeImmutable $startedAt a shift's start: its business date decides (ADR 0027 §5)
     *
     * @throws ShiftPeriodLockedException when that date is in a locked period
     */
    public function assertUnlocked(\DateTimeImmutable $startedAt): void
    {
        $date = BusinessDate::containing($startedAt, $this->timeZone);

        if ($this->isLocked($date)) {
            throw ShiftPeriodLockedException::on($date);
        }
    }

    public function isLocked(BusinessDate $date): bool
    {
        foreach ($this->periods as $period) {
            if ($period->contains($date)) {
                return true;
            }
        }

        return false;
    }
}
