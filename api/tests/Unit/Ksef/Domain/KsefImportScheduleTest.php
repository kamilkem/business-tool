<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ksef\Domain;

use App\Ksef\Domain\Model\KsefImportMethod;
use App\Ksef\Domain\Model\KsefImportSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The scheduled KSeF runs and the import's estimate (ADR 0023 §5, ADR 0039 §7). */
#[CoversClass(KsefImportSchedule::class)]
#[CoversClass(KsefImportMethod::class)]
final class KsefImportScheduleTest extends TestCase
{
    public function test_it_lists_the_runs_hourly_from_20_00_to_06_00_in_warsaw(): void
    {
        $runs = [];
        foreach (KsefImportSchedule::runsAfter(new \DateTimeImmutable('2026-11-20 04:30:00 UTC')) as $run) {
            $runs[] = $run->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i');
            if (\count($runs) >= 4) {
                break;
            }
        }

        self::assertSame(['2026-11-20 06:00', '2026-11-20 20:00', '2026-11-20 21:00', '2026-11-20 22:00'], $runs);
    }

    public function test_it_runs_02_00_twice_the_night_summer_time_ends_as_the_cron_does(): void
    {
        $runs = [];
        foreach (KsefImportSchedule::runsAfter(new \DateTimeImmutable('2026-10-24 23:30:00 UTC')) as $run) {
            $runs[] = $run->format('H:i P');
            if (\count($runs) >= 3) {
                break;
            }
        }

        // From 01:30 CEST: 02:00 CEST, 02:00 CET again, then 03:00 CET.
        self::assertSame(['00:00 +00:00', '01:00 +00:00', '02:00 +00:00'], $runs);
    }

    /** @return iterable<string, array{?int, bool, ?string}> */
    public static function estimates(): iterable
    {
        yield 'nothing known to be waiting' => [null, false, null];
        yield 'caught up' => [0, false, null];
        yield '64 or fewer: the next run' => [64, false, '2026-11-20 20:00'];
        yield 'a package being prepared: the next run' => [null, true, '2026-11-20 20:00'];
        yield 'more than 64: one package in the next run' => [9_000, false, '2026-11-20 20:00'];
        yield 'more than one package: a run each' => [25_000, false, '2026-11-20 22:00'];
    }

    #[DataProvider('estimates')]
    public function test_it_estimates_the_run_that_finishes_the_backlog(?int $backlog, bool $exportPending, ?string $expected): void
    {
        // 12:00 in Warsaw: the next run is at 20:00.
        $estimate = KsefImportSchedule::estimatedCompletion($backlog, $exportPending, new \DateTimeImmutable('2026-11-20 11:00:00 UTC'));

        self::assertSame($expected, $estimate?->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i'));
    }

    public function test_it_estimates_from_retry_after_when_it_is_later(): void
    {
        $estimate = KsefImportSchedule::estimatedCompletion(10, false, new \DateTimeImmutable('2026-11-20 19:10:00 UTC'), new \DateTimeImmutable('2026-11-20 21:20:00 UTC'));

        self::assertEquals(new \DateTimeImmutable('2026-11-20 22:00:00 UTC'), $estimate);
    }

    public function test_it_chooses_the_export_for_more_than_64_waiting(): void
    {
        self::assertSame(KsefImportMethod::Single, KsefImportMethod::for(64));
        self::assertSame(KsefImportMethod::Export, KsefImportMethod::for(65));
    }
}
