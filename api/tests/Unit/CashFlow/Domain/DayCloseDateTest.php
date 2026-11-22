<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Domain;

use App\CashFlow\Domain\Model\DayCloseDate;
use App\CashFlow\Domain\Model\DayEndTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Moments are UTC. Europe/Warsaw is UTC+1 in winter and UTC+2 in summer; in 2026 its clocks jump
 * from 02:00 to 03:00 on 29 March and fall back from 03:00 to 02:00 on 25 October.
 */
#[CoversClass(DayCloseDate::class)]
#[CoversClass(DayEndTime::class)]
final class DayCloseDateTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string}> moment (UTC), time zone, day-end time, expected date */
    public static function closes(): iterable
    {
        // A bar closing after midnight, with the default 05:00 (winter, UTC+1).
        yield 'Friday evening' => ['2026-11-20 21:30:00', 'Europe/Warsaw', '05:00', '2026-11-20'];
        yield 'one second before local midnight' => ['2026-11-20 22:59:59', 'Europe/Warsaw', '05:00', '2026-11-20'];
        yield 'local midnight' => ['2026-11-20 23:00:00', 'Europe/Warsaw', '05:00', '2026-11-20'];
        yield 'Saturday 02:30' => ['2026-11-21 01:30:00', 'Europe/Warsaw', '05:00', '2026-11-20'];
        yield 'one second before the day-end time' => ['2026-11-21 03:59:59', 'Europe/Warsaw', '05:00', '2026-11-20'];
        yield 'at the day-end time' => ['2026-11-21 04:00:00', 'Europe/Warsaw', '05:00', '2026-11-21'];
        yield 'a café at 18:00' => ['2026-11-21 17:00:00', 'Europe/Warsaw', '05:00', '2026-11-21'];

        // The day ends at midnight: always the local calendar date.
        yield 'day ends at 00:00, one second before midnight' => ['2026-11-20 22:59:59', 'Europe/Warsaw', '00:00', '2026-11-20'];
        yield 'day ends at 00:00, at midnight' => ['2026-11-20 23:00:00', 'Europe/Warsaw', '00:00', '2026-11-21'];

        // The latest day-end time.
        yield 'day ends at 12:00, at 11:59' => ['2026-11-21 10:59:00', 'Europe/Warsaw', '12:00', '2026-11-20'];
        yield 'day ends at 12:00, at noon' => ['2026-11-21 11:00:00', 'Europe/Warsaw', '12:00', '2026-11-21'];

        // A month and a year boundary.
        yield 'New Year\'s night' => ['2027-01-01 02:00:00', 'Europe/Warsaw', '05:00', '2026-12-31'];

        // Spring forward (29 March, UTC+2 from 01:00 UTC): 05:00 is still 05:00 on the wall clock.
        yield 'spring: 04:59:59 summer time' => ['2026-03-29 02:59:59', 'Europe/Warsaw', '05:00', '2026-03-28'];
        yield 'spring: 05:00 summer time' => ['2026-03-29 03:00:00', 'Europe/Warsaw', '05:00', '2026-03-29'];
        yield 'spring: 02:30 does not exist, so 03:00 is after it' => ['2026-03-29 01:00:00', 'Europe/Warsaw', '02:30', '2026-03-29'];
        yield 'spring: 01:59:59 winter time is before 02:30' => ['2026-03-29 00:59:59', 'Europe/Warsaw', '02:30', '2026-03-28'];

        // Fall back (25 October, UTC+1 from 01:00 UTC).
        yield 'autumn: 04:59:59 winter time' => ['2026-10-25 03:59:59', 'Europe/Warsaw', '05:00', '2026-10-24'];
        yield 'autumn: 05:00 winter time' => ['2026-10-25 04:00:00', 'Europe/Warsaw', '05:00', '2026-10-25'];
        yield 'autumn: the first 02:15 (summer time)' => ['2026-10-25 00:15:00', 'Europe/Warsaw', '02:30', '2026-10-24'];
        yield 'autumn: the second 02:15 (winter time)' => ['2026-10-25 01:15:00', 'Europe/Warsaw', '02:30', '2026-10-24'];

        // The organization's time zone decides, not UTC: 03:30 UTC is 22:30 the day before in New York.
        yield 'New York evening' => ['2026-11-21 03:30:00', 'America/New_York', '05:00', '2026-11-20'];
        yield 'New York after the day-end time' => ['2026-11-21 10:00:00', 'America/New_York', '05:00', '2026-11-21'];
    }

    #[DataProvider('closes')]
    public function test_it_gives_the_date_of_a_close_by_the_local_day_end_time(string $now, string $timeZone, string $dayEndsAt, string $expected): void
    {
        $clock = new MockClock($now, 'UTC');

        $date = DayCloseDate::for($clock->now(), new \DateTimeZone($timeZone), DayEndTime::fromString($dayEndsAt));

        self::assertSame($expected, $date->toString());
    }

    public function test_it_keeps_the_boundary_at_the_same_local_time_across_a_dst_change(): void
    {
        $warsaw = new \DateTimeZone('Europe/Warsaw');
        $dayEndsAt = DayEndTime::default();

        // 03:30 UTC is 04:30 in winter (before 05:00) but 05:30 in summer (after it).
        self::assertSame('2026-03-27', DayCloseDate::for(new MockClock('2026-03-28 03:30:00', 'UTC')->now(), $warsaw, $dayEndsAt)->toString());
        self::assertSame('2026-03-29', DayCloseDate::for(new MockClock('2026-03-29 03:30:00', 'UTC')->now(), $warsaw, $dayEndsAt)->toString());
    }
}
