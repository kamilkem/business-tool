<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Domain;

use App\CashFlow\Domain\Exception\InvalidDayEndTimeException;
use App\CashFlow\Domain\Model\DayEndTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DayEndTime::class)]
final class DayEndTimeTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function validTimes(): iterable
    {
        yield 'midnight' => ['00:00'];
        yield 'the default' => ['05:00'];
        yield 'minutes' => ['04:30'];
        yield 'before noon' => ['11:59'];
        yield 'noon' => ['12:00'];
    }

    #[DataProvider('validTimes')]
    public function test_it_parses_a_time_from_midnight_to_noon(string $value): void
    {
        self::assertSame($value, DayEndTime::fromString($value)->toString());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTimes(): iterable
    {
        yield 'after noon' => ['12:01'];
        yield 'the evening' => ['18:00'];
        yield 'one digit for the hour' => ['5:00'];
        yield 'with seconds' => ['05:00:00'];
        yield 'hour 24' => ['24:00'];
        yield 'minute 60' => ['05:60'];
        yield 'blank' => [''];
        yield 'spaces' => [' 05:00'];
        yield 'a trailing new line' => ["05:00\n"];
    }

    #[DataProvider('invalidTimes')]
    public function test_it_refuses_anything_else_with_a_code(string $value): void
    {
        try {
            DayEndTime::fromString($value);
            self::fail('Expected an InvalidDayEndTimeException.');
        } catch (InvalidDayEndTimeException $e) {
            self::assertSame('cash_flow.invalid_day_end_time', $e->errorCode());
            self::assertSame(['dayEndsAt' => $value, 'latest' => '12:00'], $e->errorParameters());
        }
    }

    public function test_it_defaults_to_five_in_the_morning(): void
    {
        self::assertSame('05:00', DayEndTime::default()->toString());
        self::assertTrue(DayEndTime::default()->equals(DayEndTime::fromString('05:00')));
        self::assertFalse(DayEndTime::default()->equals(DayEndTime::fromString('04:00')));
    }
}
