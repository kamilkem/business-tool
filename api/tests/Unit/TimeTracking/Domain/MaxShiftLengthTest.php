<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Domain;

use App\TimeTracking\Domain\Exception\InvalidMaxShiftLengthException;
use App\TimeTracking\Domain\Model\MaxShiftLength;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaxShiftLength::class)]
#[CoversClass(InvalidMaxShiftLengthException::class)]
final class MaxShiftLengthTest extends TestCase
{
    public function test_it_is_twelve_hours_by_default(): void
    {
        self::assertSame(12, MaxShiftLength::default()->hours);
    }

    public function test_it_takes_whole_hours_from_one_to_twenty_four(): void
    {
        self::assertSame(1, MaxShiftLength::ofHours(1)->hours);
        self::assertSame(24, MaxShiftLength::ofHours(24)->hours);
    }

    /** @return iterable<string, array{int}> */
    public static function outOfRange(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-3];
        yield 'more than a day' => [25];
    }

    #[DataProvider('outOfRange')]
    public function test_it_refuses_hours_out_of_range(int $hours): void
    {
        try {
            MaxShiftLength::ofHours($hours);
            self::fail('Expected InvalidMaxShiftLengthException.');
        } catch (InvalidMaxShiftLengthException $e) {
            self::assertSame('time_tracking.invalid_max_shift_length', $e->errorCode());
            self::assertSame(['maxShiftHours' => $hours, 'min' => 1, 'max' => 24], $e->errorParameters());
        }
    }

    public function test_it_is_exceeded_only_by_a_shift_longer_than_the_hours(): void
    {
        $max = MaxShiftLength::ofHours(12);
        $start = new \DateTimeImmutable('2026-11-22 18:00:00 UTC');

        self::assertFalse($max->isExceededBy($start, new \DateTimeImmutable('2026-11-23 06:00:00 UTC')), 'Exactly 12 hours is within the limit.');
        self::assertTrue($max->isExceededBy($start, new \DateTimeImmutable('2026-11-23 06:00:01 UTC')));
    }

    public function test_it_gives_the_start_before_which_a_shift_runs_too_long(): void
    {
        self::assertEquals(
            new \DateTimeImmutable('2026-11-22 22:00:00 UTC'),
            MaxShiftLength::ofHours(12)->startedBefore(new \DateTimeImmutable('2026-11-23 10:00:00 UTC')),
        );
    }
}
