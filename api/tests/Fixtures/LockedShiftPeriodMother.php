<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Shared\Domain\Time\BusinessPeriod;
use App\TimeTracking\Domain\Model\LockedShiftPeriod;
use App\TimeTracking\Domain\Model\ShiftPeriodLocks;
use Symfony\Component\Uid\Uuid;

final class LockedShiftPeriodMother
{
    /** A calendar month locked by a payroll run (a new id unless given). */
    public static function month(int $year, int $month, ?Uuid $lockedBy = null, string $lockedAt = '2026-11-05 10:00:00'): LockedShiftPeriod
    {
        return new LockedShiftPeriod(Uuid::v7(), $lockedBy ?? Uuid::v7(), BusinessPeriod::month($year, $month), new \DateTimeImmutable($lockedAt));
    }

    /** October 2026 locked, for an organization in the given time zone. */
    public static function octoberLockedIn(string $timeZone = 'Europe/Warsaw'): ShiftPeriodLocks
    {
        return ShiftPeriodLocks::of([self::month(2026, 10)], new \DateTimeZone($timeZone));
    }
}
