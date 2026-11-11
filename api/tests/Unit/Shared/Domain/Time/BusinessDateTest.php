<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Time;

use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;
use App\Shared\Domain\Time\UtcRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(BusinessDate::class)]
final class BusinessDateTest extends TestCase
{
    private const int HOUR = 3600;

    public function test_it_parses_a_ymd_string(): void
    {
        $date = BusinessDate::fromString('2026-11-11');

        self::assertSame(2026, $date->year);
        self::assertSame(11, $date->month);
        self::assertSame(11, $date->day);
        self::assertSame('2026-11-11', $date->toString());
        self::assertSame('2026-11-11', (string) $date);
    }

    /** @return iterable<string, array{string}> */
    public static function notYmd(): iterable
    {
        yield 'empty' => [''];
        yield 'no leading zeros' => ['2026-1-1'];
        yield 'Polish notation' => ['11.11.2026'];
        yield 'with a time' => ['2026-11-11T00:00:00'];
        yield 'with a time zone' => ['2026-11-11+01:00'];
        yield 'leading space' => [' 2026-11-11'];
        yield 'trailing newline' => ["2026-11-11\n"];
        yield 'no separators' => ['20261111'];
        yield 'words' => ['today'];
    }

    #[DataProvider('notYmd')]
    public function test_it_rejects_strings_not_in_the_ymd_format(string $value): void
    {
        $this->expectException(InvalidBusinessDateException::class);
        $this->expectExceptionMessage(\sprintf('"%s" is not a date in the YYYY-MM-DD format.', $value));

        BusinessDate::fromString($value);
    }

    /** @return iterable<string, array{string}> */
    public static function notACalendarDate(): iterable
    {
        yield '29 February in a common year' => ['2026-02-29'];
        yield '31 April' => ['2026-04-31'];
        yield 'month 13' => ['2026-13-01'];
        yield 'month 0' => ['2026-00-10'];
        yield 'day 0' => ['2026-11-00'];
        yield 'year 0' => ['0000-01-01'];
    }

    #[DataProvider('notACalendarDate')]
    public function test_it_rejects_dates_that_do_not_exist(string $value): void
    {
        $this->expectException(InvalidBusinessDateException::class);
        $this->expectExceptionMessage($value.' is not a calendar date.');

        BusinessDate::fromString($value);
    }

    public function test_it_accepts_29_february_in_a_leap_year(): void
    {
        self::assertSame('2028-02-29', BusinessDate::of(2028, 2, 29)->toString());
    }

    public function test_it_converts_a_winter_day_in_warsaw_to_24_utc_hours(): void
    {
        $range = BusinessDate::of(2026, 11, 11)->toUtcRange(new \DateTimeZone('Europe/Warsaw'));

        self::assertRange('2026-11-10T23:00:00+00:00', '2026-11-11T23:00:00+00:00', $range);
        self::assertSame(24 * self::HOUR, $range->durationInSeconds());
    }

    public function test_it_makes_the_last_sunday_of_march_a_23_hour_day_in_warsaw(): void
    {
        $range = BusinessDate::of(2026, 3, 29)->toUtcRange(new \DateTimeZone('Europe/Warsaw'));

        self::assertRange('2026-03-28T23:00:00+00:00', '2026-03-29T22:00:00+00:00', $range);
        self::assertSame(23 * self::HOUR, $range->durationInSeconds());
    }

    public function test_it_makes_the_last_sunday_of_october_a_25_hour_day_in_warsaw(): void
    {
        $range = BusinessDate::of(2026, 10, 25)->toUtcRange(new \DateTimeZone('Europe/Warsaw'));

        self::assertRange('2026-10-24T22:00:00+00:00', '2026-10-25T23:00:00+00:00', $range);
        self::assertSame(25 * self::HOUR, $range->durationInSeconds());
    }

    public function test_it_starts_a_day_at_the_first_local_time_that_exists_when_the_clocks_skip_midnight(): void
    {
        // Chile moves its clocks from 00:00 to 01:00 on the first Sunday of September.
        $range = BusinessDate::of(2026, 9, 6)->toUtcRange(new \DateTimeZone('America/Santiago'));

        self::assertRange('2026-09-06T04:00:00+00:00', '2026-09-07T03:00:00+00:00', $range);
        self::assertSame(23 * self::HOUR, $range->durationInSeconds());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function instantsAroundLocalMidnight(): iterable
    {
        yield 'Warsaw winter, 23:59:59' => ['Europe/Warsaw', '2026-11-11T22:59:59+00:00', '2026-11-11'];
        yield 'Warsaw winter, 00:00:00' => ['Europe/Warsaw', '2026-11-11T23:00:00+00:00', '2026-11-12'];
        yield 'Warsaw summer, 23:59:59' => ['Europe/Warsaw', '2026-07-01T21:59:59+00:00', '2026-07-01'];
        yield 'Warsaw summer, 00:00:00' => ['Europe/Warsaw', '2026-07-01T22:00:00+00:00', '2026-07-02'];
        yield 'Warsaw, the repeated 02:30 on the 25-hour day' => ['Europe/Warsaw', '2026-10-25T01:30:00+00:00', '2026-10-25'];
        yield 'Auckland summer (UTC+13), 23:59:59' => ['Pacific/Auckland', '2026-11-11T10:59:59+00:00', '2026-11-11'];
        yield 'Auckland summer (UTC+13), 00:00:00' => ['Pacific/Auckland', '2026-11-11T11:00:00+00:00', '2026-11-12'];
        yield 'Auckland winter (UTC+12), 23:59:59' => ['Pacific/Auckland', '2026-07-01T11:59:59+00:00', '2026-07-01'];
        yield 'Auckland winter (UTC+12), 00:00:00' => ['Pacific/Auckland', '2026-07-01T12:00:00+00:00', '2026-07-02'];
        yield 'Honolulu (UTC-10), 23:59:59' => ['Pacific/Honolulu', '2026-11-12T09:59:59+00:00', '2026-11-11'];
        yield 'Honolulu (UTC-10), 00:00:00' => ['Pacific/Honolulu', '2026-11-12T10:00:00+00:00', '2026-11-12'];
    }

    #[DataProvider('instantsAroundLocalMidnight')]
    public function test_it_puts_instants_around_local_midnight_on_the_right_date(string $timeZone, string $instant, string $expected): void
    {
        $zone = new \DateTimeZone($timeZone);
        $date = BusinessDate::containing(new \DateTimeImmutable($instant), $zone);

        self::assertSame($expected, $date->toString());
        self::assertTrue($date->toUtcRange($zone)->contains(new \DateTimeImmutable($instant)));
    }

    public function test_it_reads_the_date_in_the_given_zone_whatever_the_offset_of_the_instant(): void
    {
        $instant = new \DateTimeImmutable('2026-11-11T23:30:00+01:00');

        self::assertSame('2026-11-11', BusinessDate::containing($instant, new \DateTimeZone('Europe/Warsaw'))->toString());
        self::assertSame('2026-11-11', BusinessDate::containing($instant, new \DateTimeZone('UTC'))->toString());
        self::assertSame('2026-11-12', BusinessDate::containing($instant, new \DateTimeZone('Pacific/Auckland'))->toString());
    }

    public function test_it_tells_today_from_the_clock(): void
    {
        $clock = new MockClock('2026-11-11 23:30:00', 'UTC');

        self::assertSame('2026-11-12', BusinessDate::containing($clock->now(), new \DateTimeZone('Europe/Warsaw'))->toString());
        self::assertSame('2026-11-11', BusinessDate::containing($clock->now(), new \DateTimeZone('America/New_York'))->toString());
    }

    public function test_it_starts_a_new_year_in_auckland_while_it_is_still_the_old_year_in_utc(): void
    {
        $auckland = new \DateTimeZone('Pacific/Auckland');

        self::assertSame('2026-12-31', BusinessDate::containing(new \DateTimeImmutable('2026-12-31T10:59:59+00:00'), $auckland)->toString());
        self::assertSame('2027-01-01', BusinessDate::containing(new \DateTimeImmutable('2026-12-31T11:00:00+00:00'), $auckland)->toString());
        self::assertRange('2026-12-31T11:00:00+00:00', '2027-01-01T11:00:00+00:00', BusinessDate::of(2027, 1, 1)->toUtcRange($auckland));
    }

    /** @return iterable<string, array{string, string}> */
    public static function nextDays(): iterable
    {
        yield 'within a month' => ['2026-11-11', '2026-11-12'];
        yield 'month boundary' => ['2026-01-31', '2026-02-01'];
        yield 'end of February, common year' => ['2026-02-28', '2026-03-01'];
        yield 'end of February, leap year' => ['2028-02-28', '2028-02-29'];
        yield 'year boundary' => ['2026-12-31', '2027-01-01'];
        yield 'DST change in Europe' => ['2026-03-29', '2026-03-30'];
    }

    #[DataProvider('nextDays')]
    public function test_it_steps_one_calendar_day_forward_and_back(string $day, string $nextDay): void
    {
        self::assertSame($nextDay, BusinessDate::fromString($day)->next()->toString());
        self::assertSame($day, BusinessDate::fromString($nextDay)->previous()->toString());
    }

    public function test_it_compares_dates(): void
    {
        $lastOfYear = BusinessDate::of(2026, 12, 31);
        $newYear = BusinessDate::of(2027, 1, 1);

        self::assertTrue($lastOfYear->isBefore($newYear));
        self::assertFalse($lastOfYear->isAfter($newYear));
        self::assertTrue($newYear->isAfter($lastOfYear));
        self::assertFalse($newYear->isBefore($newYear));
        self::assertTrue($newYear->equals(BusinessDate::fromString('2027-01-01')));
        self::assertFalse($newYear->equals($lastOfYear));
    }

    /** @return iterable<string, array{string}> */
    public static function timeZones(): iterable
    {
        yield 'Europe/Warsaw' => ['Europe/Warsaw'];
        yield 'Pacific/Auckland' => ['Pacific/Auckland'];
        yield 'America/Santiago' => ['America/Santiago'];
    }

    #[DataProvider('timeZones')]
    public function test_it_tiles_a_whole_year_with_consecutive_days_and_one_short_and_one_long_day(string $timeZone): void
    {
        $zone = new \DateTimeZone($timeZone);
        $day = BusinessDate::of(2026, 1, 1);
        $durations = [];

        while (2026 === $day->year) {
            $range = $day->toUtcRange($zone);
            self::assertEquals($range->to, $day->next()->toUtcRange($zone)->from, $day.' must end where the next day starts.');
            $durations[] = $range->durationInSeconds();
            $day = $day->next();
        }

        $counts = array_count_values($durations);
        self::assertSame(365 * 24 * self::HOUR, array_sum($durations));
        self::assertSame([23 * self::HOUR => 1, 24 * self::HOUR => 363, 25 * self::HOUR => 1], [
            23 * self::HOUR => $counts[23 * self::HOUR] ?? 0,
            24 * self::HOUR => $counts[24 * self::HOUR] ?? 0,
            25 * self::HOUR => $counts[25 * self::HOUR] ?? 0,
        ]);
    }

    private static function assertRange(string $from, string $to, UtcRange $range): void
    {
        self::assertSame($from, $range->from->format(\DATE_ATOM));
        self::assertSame($to, $range->to->format(\DATE_ATOM));
    }
}
