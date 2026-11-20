<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Domain;

use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Tests\Fixtures\LockedShiftPeriodMother;
use App\TimeTracking\Domain\Exception\ShiftPeriodLockedException;
use App\TimeTracking\Domain\Model\LockedShiftPeriod;
use App\TimeTracking\Domain\Model\ShiftPeriodLocks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ShiftPeriodLocks::class)]
#[CoversClass(LockedShiftPeriod::class)]
#[CoversClass(ShiftPeriodLockedException::class)]
final class ShiftPeriodLocksTest extends TestCase
{
    public function test_it_keeps_the_period_what_locked_it_and_when(): void
    {
        $id = Uuid::v7();
        $runId = Uuid::v7();
        $lockedAt = new \DateTimeImmutable('2026-11-05 10:00:00');

        $period = new LockedShiftPeriod($id, $runId, BusinessPeriod::month(2026, 10), $lockedAt);

        self::assertSame($id, $period->getId());
        self::assertSame($runId, $period->getLockedBy());
        self::assertTrue(BusinessPeriod::fromStrings('2026-10-01', '2026-10-31')->equals($period->getPeriod()));
        self::assertSame($lockedAt, $period->getLockedAt());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function dates(): iterable
    {
        yield 'the day before' => ['2026-09-30', false];
        yield 'the first day' => ['2026-10-01', true];
        yield 'the last day' => ['2026-10-31', true];
        yield 'the day after' => ['2026-11-01', false];
    }

    #[DataProvider('dates')]
    public function test_it_contains_its_first_and_last_date(string $date, bool $locked): void
    {
        self::assertSame($locked, LockedShiftPeriodMother::month(2026, 10)->contains(BusinessDate::fromString($date)));
        self::assertSame($locked, LockedShiftPeriodMother::octoberLockedIn()->isLocked(BusinessDate::fromString($date)));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function starts(): iterable
    {
        yield 'last second of October in Warsaw' => ['Europe/Warsaw', '2026-10-31T22:59:59Z', true];
        yield 'midnight of 1 November in Warsaw' => ['Europe/Warsaw', '2026-10-31T23:00:00Z', false];
        yield 'the same instant in UTC' => ['UTC', '2026-10-31T23:00:00Z', true];
        yield '1 November 03:59 UTC, still 31 October in New York' => ['America/New_York', '2026-11-01T03:59:59Z', true];
        yield '1 November 04:00 UTC, 1 November in New York' => ['America/New_York', '2026-11-01T04:00:00Z', false];
        yield '1 October 00:00 in Warsaw' => ['Europe/Warsaw', '2026-09-30T22:00:00Z', true];
        yield '30 September 23:59 in Warsaw' => ['Europe/Warsaw', '2026-09-30T21:59:59Z', false];
    }

    #[DataProvider('starts')]
    public function test_it_decides_by_the_start_date_in_the_organizations_time_zone(string $timeZone, string $startedAt, bool $locked): void
    {
        $locks = LockedShiftPeriodMother::octoberLockedIn($timeZone);

        if ($locked) {
            $this->expectException(ShiftPeriodLockedException::class);
        }

        $locks->assertUnlocked(new \DateTimeImmutable($startedAt));

        $this->addToAssertionCount(1);
    }

    public function test_it_names_the_locked_date(): void
    {
        $this->expectException(ShiftPeriodLockedException::class);
        $this->expectExceptionMessage('2026-10-31');

        LockedShiftPeriodMother::octoberLockedIn()->assertUnlocked(new \DateTimeImmutable('2026-10-31T19:00:00Z'));
    }

    public function test_it_checks_every_locked_period(): void
    {
        $locks = ShiftPeriodLocks::of(
            [LockedShiftPeriodMother::month(2026, 9), LockedShiftPeriodMother::month(2026, 10)],
            new \DateTimeZone('Europe/Warsaw'),
        );

        self::assertTrue($locks->isLocked(BusinessDate::of(2026, 9, 15)));
        self::assertTrue($locks->isLocked(BusinessDate::of(2026, 10, 15)));
        self::assertFalse($locks->isLocked(BusinessDate::of(2026, 8, 31)));
    }

    public function test_it_locks_nothing_when_nothing_is_locked(): void
    {
        ShiftPeriodLocks::none()->assertUnlocked(new \DateTimeImmutable('2026-10-15T12:00:00Z'));

        self::assertFalse(ShiftPeriodLocks::none()->isLocked(BusinessDate::of(2026, 10, 15)));
    }
}
