<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payroll\Domain;

use App\Payroll\Domain\Exception\InvalidRoundingException;
use App\Payroll\Domain\Model\Rounding;
use App\Payroll\Domain\Model\RoundingDirection;
use App\Payroll\Domain\Model\RoundingUnit;
use App\Payroll\Domain\Model\ShiftLine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Rounding::class)]
#[CoversClass(RoundingUnit::class)]
#[CoversClass(ShiftLine::class)]
final class RoundingTest extends TestCase
{
    /** @return iterable<string, array{string, string, int, int}> */
    public static function lengths(): iterable
    {
        // 7h 07m 30s = 25650 s
        yield 'exact keeps the seconds' => ['none', 'up', 25_650, 25_650];
        yield 'nearest 15 rounds a half up' => ['15', 'nearest', 25_650, 26_100];
        yield 'nearest 15 below the half' => ['15', 'nearest', 25_649, 25_200];
        yield 'up 15' => ['15', 'up', 25_201, 26_100];
        yield 'up 15 on the unit stays' => ['15', 'up', 25_200, 25_200];
        yield 'down 15' => ['15', 'down', 26_099, 25_200];
        yield 'nearest 30' => ['30', 'nearest', 25_650, 25_200];
        yield 'up 30' => ['30', 'up', 25_650, 27_000];
        yield 'down 60' => ['60', 'down', 25_650, 25_200];
        yield 'nearest 60 of half an hour rounds up' => ['60', 'nearest', 1_800, 3_600];
        yield 'down 60 of a short shift is nothing' => ['60', 'down', 3_599, 0];
        yield 'nothing stays nothing' => ['60', 'up', 0, 0];
    }

    #[DataProvider('lengths')]
    public function test_it_rounds_a_shifts_length(string $unit, string $direction, int $seconds, int $paid): void
    {
        self::assertSame($paid, Rounding::fromStrings($unit, $direction)->apply($seconds));
    }

    public function test_it_refuses_an_unknown_unit_or_direction(): void
    {
        $this->expectException(InvalidRoundingException::class);

        Rounding::fromStrings('45', 'nearest');
    }

    public function test_it_refuses_an_unknown_direction(): void
    {
        $this->expectException(InvalidRoundingException::class);

        Rounding::fromStrings('15', 'sideways');
    }

    public function test_it_rounds_minor_units_times_3600_half_up(): void
    {
        self::assertSame(0, ShiftLine::roundHalfUp(1_799));
        self::assertSame(1, ShiftLine::roundHalfUp(1_800));
        self::assertSame(1, ShiftLine::roundHalfUp(5_399));
        self::assertSame(2, ShiftLine::roundHalfUp(5_400));
        self::assertTrue(new Rounding(RoundingUnit::None, RoundingDirection::Nearest)->equals(Rounding::exact()));
    }
}
