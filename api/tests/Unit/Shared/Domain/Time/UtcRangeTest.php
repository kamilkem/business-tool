<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Time;

use App\Shared\Domain\Time\UtcRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UtcRange::class)]
final class UtcRangeTest extends TestCase
{
    public function test_it_includes_its_start_and_excludes_its_end(): void
    {
        $range = new UtcRange(new \DateTimeImmutable('2026-11-10T23:00:00+00:00'), new \DateTimeImmutable('2026-11-11T23:00:00+00:00'));

        self::assertFalse($range->contains(new \DateTimeImmutable('2026-11-10T22:59:59+00:00')));
        self::assertTrue($range->contains(new \DateTimeImmutable('2026-11-10T23:00:00+00:00')));
        self::assertTrue($range->contains(new \DateTimeImmutable('2026-11-11T22:59:59+00:00')));
        self::assertFalse($range->contains(new \DateTimeImmutable('2026-11-11T23:00:00+00:00')));
    }

    public function test_it_stores_both_ends_in_utc(): void
    {
        $range = new UtcRange(new \DateTimeImmutable('2026-11-11T00:00:00+01:00'), new \DateTimeImmutable('2026-11-12T00:00:00+13:00'));

        self::assertSame('2026-11-10T23:00:00+00:00', $range->from->format(\DATE_ATOM));
        self::assertSame('2026-11-11T11:00:00+00:00', $range->to->format(\DATE_ATOM));
        self::assertSame('UTC', $range->from->getTimezone()->getName());
        self::assertSame(12 * 3600, $range->durationInSeconds());
    }

    public function test_it_rejects_a_range_that_does_not_end_after_it_starts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $moment = new \DateTimeImmutable('2026-11-11T12:00:00+00:00');
        new UtcRange($moment, $moment);
    }
}
