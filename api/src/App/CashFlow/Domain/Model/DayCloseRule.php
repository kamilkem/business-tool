<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

use App\CashFlow\Domain\Exception\InvalidDayCloseDateException;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Domain\Time\BusinessDate;

/**
 * How the organization's end-of-day closes get their business date (ADR 0033 §5): the mode and the
 * day-end time of its DayCloseSettings, or the defaults (day_end_time, 05:00) when it has none.
 */
final readonly class DayCloseRule
{
    public function __construct(
        public DayCloseDateMode $mode,
        public DayEndTime $dayEndsAt,
    ) {
    }

    public static function default(): self
    {
        return new self(DayCloseDateMode::DayEndTime, DayEndTime::default());
    }

    /** The date a close at this moment gets when nobody picks one, and the one the time clock preselects. */
    public function proposedDate(\DateTimeImmutable $now, \DateTimeZone $timeZone): BusinessDate
    {
        return DayCloseDate::for($now, $timeZone, $this->dayEndsAt);
    }

    /**
     * The business date of a close made now by a member of this role, from the date they picked, if
     * any (null: the proposed date).
     *
     * - Owners and managers may pick any date up to today, in either mode.
     * - An employee gets the proposed date in `day_end_time` mode: a picked date must be that one.
     * - An employee picks today or yesterday in `employee_picks` mode.
     *
     * "Today" and "yesterday" are calendar dates in the organization's time zone.
     *
     * @throws InvalidDayCloseDateException when the picked date is not allowed
     */
    public function dateOfClose(?BusinessDate $picked, MembershipRole $role, \DateTimeImmutable $now, \DateTimeZone $timeZone): BusinessDate
    {
        $proposed = $this->proposedDate($now, $timeZone);
        if (null === $picked) {
            return $proposed;
        }

        $today = BusinessDate::containing($now, $timeZone);
        if ($role->canManageOrganization()) {
            if ($picked->isAfter($today)) {
                throw InvalidDayCloseDateException::inTheFuture($picked->toString(), $today->toString());
            }

            return $picked;
        }

        if (DayCloseDateMode::DayEndTime === $this->mode) {
            if (!$picked->equals($proposed)) {
                throw InvalidDayCloseDateException::notChosenByEmployee($picked->toString(), $proposed->toString());
            }

            return $picked;
        }

        if (!$picked->equals($today) && !$picked->equals($today->previous())) {
            throw InvalidDayCloseDateException::notTodayOrYesterday($picked->toString(), $today->toString());
        }

        return $picked;
    }

    public function equals(self $other): bool
    {
        return $this->mode === $other->mode && $this->dayEndsAt->equals($other->dayEndsAt);
    }
}
