<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Time;

use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;
use App\Shared\Domain\Time\Exception\InvalidBusinessPeriodException;
use App\Shared\Domain\Time\UtcRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessPeriod::class)]
final class BusinessPeriodTest extends TestCase
{
    private const int HOUR = 3600;
    private const int DAY = 24 * self::HOUR;

    public function test_it_covers_a_month_in_warsaw(): void
    {
        $period = BusinessPeriod::month(2026, 11);

        self::assertSame('2026-11-01', $period->first->toString());
        self::assertSame('2026-11-30', $period->last->toString());
        self::assertSame(30, $period->dayCount());
        self::assertRange('2026-10-31T23:00:00+00:00', '2026-11-30T23:00:00+00:00', $period->toUtcRange(new \DateTimeZone('Europe/Warsaw')));
    }

    public function test_it_makes_march_an_hour_shorter_in_warsaw(): void
    {
        $range = BusinessPeriod::month(2026, 3)->toUtcRange(new \DateTimeZone('Europe/Warsaw'));

        self::assertRange('2026-02-28T23:00:00+00:00', '2026-03-31T22:00:00+00:00', $range);
        self::assertSame(31 * self::DAY - self::HOUR, $range->durationInSeconds());
    }

    public function test_it_makes_october_an_hour_longer_in_warsaw(): void
    {
        $range = BusinessPeriod::month(2026, 10)->toUtcRange(new \DateTimeZone('Europe/Warsaw'));

        self::assertRange('2026-09-30T22:00:00+00:00', '2026-10-31T23:00:00+00:00', $range);
        self::assertSame(31 * self::DAY + self::HOUR, $range->durationInSeconds());
    }

    public function test_it_covers_a_month_in_auckland(): void
    {
        $auckland = new \DateTimeZone('Pacific/Auckland');

        self::assertRange('2026-10-31T11:00:00+00:00', '2026-11-30T11:00:00+00:00', BusinessPeriod::month(2026, 11)->toUtcRange($auckland));

        // New Zealand summer time ends on the first Sunday of April: April is an hour longer there.
        $april = BusinessPeriod::month(2026, 4)->toUtcRange($auckland);
        self::assertRange('2026-03-31T11:00:00+00:00', '2026-04-30T12:00:00+00:00', $april);
        self::assertSame(30 * self::DAY + self::HOUR, $april->durationInSeconds());
    }

    public function test_it_ends_december_at_new_year(): void
    {
        $december = BusinessPeriod::month(2026, 12);

        self::assertSame('2026-12-31', $december->last->toString());
        self::assertRange('2026-11-30T23:00:00+00:00', '2026-12-31T23:00:00+00:00', $december->toUtcRange(new \DateTimeZone('Europe/Warsaw')));
    }

    public function test_it_spans_a_year_boundary(): void
    {
        $period = BusinessPeriod::fromStrings('2026-12-28', '2027-01-03');

        self::assertSame(7, $period->dayCount());
        self::assertTrue($period->contains(BusinessDate::of(2027, 1, 1)));
        self::assertRange('2026-12-27T23:00:00+00:00', '2027-01-03T23:00:00+00:00', $period->toUtcRange(new \DateTimeZone('Europe/Warsaw')));
    }

    public function test_it_knows_how_long_february_is(): void
    {
        self::assertSame('2026-02-28', BusinessPeriod::month(2026, 2)->last->toString());
        self::assertSame('2028-02-29', BusinessPeriod::month(2028, 2)->last->toString());
        self::assertSame(29, BusinessPeriod::month(2028, 2)->dayCount());
    }

    public function test_it_finds_the_month_of_a_date(): void
    {
        self::assertTrue(BusinessPeriod::monthOf(BusinessDate::of(2026, 2, 14))->equals(BusinessPeriod::month(2026, 2)));
    }

    public function test_it_covers_a_single_day_like_the_date_itself(): void
    {
        $date = BusinessDate::of(2026, 10, 25);
        $warsaw = new \DateTimeZone('Europe/Warsaw');
        $period = BusinessPeriod::day($date);

        self::assertSame(1, $period->dayCount());
        self::assertEquals($date->toUtcRange($warsaw), $period->toUtcRange($warsaw));
        self::assertTrue(BusinessPeriod::fromDates($date, $date)->equals($period));
    }

    public function test_it_includes_both_ends(): void
    {
        $period = BusinessPeriod::fromStrings('2026-11-01', '2026-11-30');

        self::assertFalse($period->contains(BusinessDate::of(2026, 10, 31)));
        self::assertTrue($period->contains(BusinessDate::of(2026, 11, 1)));
        self::assertTrue($period->contains(BusinessDate::of(2026, 11, 15)));
        self::assertTrue($period->contains(BusinessDate::of(2026, 11, 30)));
        self::assertFalse($period->contains(BusinessDate::of(2026, 12, 1)));
    }

    public function test_it_counts_calendar_days_across_a_dst_change(): void
    {
        self::assertSame(31, BusinessPeriod::fromStrings('2026-03-01', '2026-03-31')->dayCount());
        self::assertSame(365, BusinessPeriod::fromStrings('2026-01-01', '2026-12-31')->dayCount());
    }

    public function test_it_rejects_a_period_that_ends_before_it_starts(): void
    {
        $this->expectException(InvalidBusinessPeriodException::class);
        $this->expectExceptionMessage('A period cannot end on 2026-11-01: it starts on 2026-11-02.');

        BusinessPeriod::fromStrings('2026-11-02', '2026-11-01');
    }

    /** @return iterable<string, array{string, string}> */
    public static function badStrings(): iterable
    {
        yield 'first is not a date' => ['yesterday', '2026-11-30'];
        yield 'last is not a date' => ['2026-11-01', '2026-11-31'];
    }

    #[DataProvider('badStrings')]
    public function test_it_rejects_strings_that_are_not_dates(string $first, string $last): void
    {
        $this->expectException(InvalidBusinessDateException::class);

        BusinessPeriod::fromStrings($first, $last);
    }

    /** @return iterable<string, array{int, int}> */
    public static function notAMonth(): iterable
    {
        yield 'month 0' => [2026, 0];
        yield 'month 13' => [2026, 13];
        yield 'year 0' => [0, 1];
    }

    #[DataProvider('notAMonth')]
    public function test_it_rejects_a_month_that_does_not_exist(int $year, int $month): void
    {
        $this->expectException(InvalidBusinessPeriodException::class);
        $this->expectExceptionMessage(\sprintf('%04d-%02d is not a month.', $year, $month));

        BusinessPeriod::month($year, $month);
    }

    public function test_it_parses_a_month(): void
    {
        self::assertTrue(BusinessPeriod::monthFromString('2026-10')->equals(BusinessPeriod::month(2026, 10)));
    }

    /** @return iterable<string, array{string}> */
    public static function notAMonthString(): iterable
    {
        yield 'a date' => ['2026-10-01'];
        yield 'no leading zero' => ['2026-1'];
        yield 'words' => ['October'];
    }

    #[DataProvider('notAMonthString')]
    public function test_it_rejects_a_string_that_is_not_a_month(string $value): void
    {
        $this->expectException(InvalidBusinessPeriodException::class);
        $this->expectExceptionMessage(\sprintf('"%s" is not a month (YYYY-MM).', $value));

        BusinessPeriod::monthFromString($value);
    }

    public function test_it_rejects_a_month_string_that_does_not_exist(): void
    {
        $this->expectException(InvalidBusinessPeriodException::class);
        $this->expectExceptionMessage('2026-13 is not a month.');

        BusinessPeriod::monthFromString('2026-13');
    }

    private static function assertRange(string $from, string $to, UtcRange $range): void
    {
        self::assertSame($from, $range->from->format(\DATE_ATOM));
        self::assertSame($to, $range->to->format(\DATE_ATOM));
    }
}
