<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Organization\Domain\Model\MembershipRole;
use App\Payroll\Domain\Model\DayRate;
use App\Payroll\Domain\Model\HourlyRate;
use App\Payroll\Domain\Model\HourlyRateHistory;
use App\Payroll\Domain\Model\LockedPayrollDates;
use App\Shared\Domain\Time\BusinessDate;
use Symfony\Component\Uid\Uuid;

/** Hourly and day rates set by an owner, without the history or lock checks of a real write. */
final class PayRateMother
{
    public static function hourly(Uuid $userId, string $effectiveFrom, int $amount = 3000, string $currency = 'PLN', string $createdAt = '2026-01-05 09:00:00'): HourlyRate
    {
        return HourlyRate::set(
            Uuid::v7(),
            HourlyRateHistory::of($userId, []),
            BusinessDate::fromString($effectiveFrom),
            $amount,
            $currency,
            LockedPayrollDates::none(),
            Uuid::v7(),
            MembershipRole::Owner,
            new \DateTimeImmutable($createdAt),
        );
    }

    public static function day(Uuid $userId, string $date, int $amount = 4500, string $currency = 'PLN', string $createdAt = '2026-01-05 09:00:00'): DayRate
    {
        return DayRate::set(
            Uuid::v7(),
            $userId,
            BusinessDate::fromString($date),
            $amount,
            $currency,
            null,
            LockedPayrollDates::none(),
            Uuid::v7(),
            MembershipRole::Owner,
            new \DateTimeImmutable($createdAt),
        );
    }
}
