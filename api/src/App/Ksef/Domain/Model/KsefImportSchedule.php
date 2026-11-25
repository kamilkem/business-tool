<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Model;

/**
 * When the scheduled KSeF imports run (ADR 0023 §5): once an hour from 20:00 to 06:00 Polish time,
 * when KSeF's limits are higher. The cron entry (Ui\Scheduler\DefaultSchedule) and the import's
 * estimate (ADR 0039 §7) both read it here.
 */
final class KsefImportSchedule
{
    public const string CRON = '0 20-23,0-6 * * *';
    public const string TIME_ZONE = 'Europe/Warsaw';
    /** The local hours of CRON. */
    private const array HOURS = [20, 21, 22, 23, 0, 1, 2, 3, 4, 5, 6];

    /**
     * The start of the scheduled run expected to finish the import, or null when nothing is known to
     * be waiting (ADR 0039 §7): the next run when at most 64 invoices are left or a package is being
     * prepared, else one run per package of 10,000. Runs are counted from `$now`, or from KSeF's
     * Retry-After if it is later.
     */
    public static function estimatedCompletion(?int $backlogCount, bool $exportPending, \DateTimeImmutable $now, ?\DateTimeImmutable $retryNotBefore = null): ?\DateTimeImmutable
    {
        if (!$exportPending && (null === $backlogCount || $backlogCount <= 0)) {
            return null;
        }
        $method = $exportPending ? KsefImportMethod::Export : KsefImportMethod::for((int) $backlogCount);
        $runs = $exportPending || KsefImportMethod::Single === $method ? 1 : (int) ceil((int) $backlogCount / $method->invoicesPerRun());

        $from = null !== $retryNotBefore && $retryNotBefore > $now ? $retryNotBefore : $now;
        $run = null;
        foreach (self::runsAfter($from) as $run) {
            if (--$runs <= 0) {
                break;
            }
        }

        return $run;
    }

    /**
     * The scheduled runs after `$from`, in UTC: every whole hour whose local hour is in CRON (twice
     * 02:00 the night summer time ends, as the cron does).
     *
     * @return \Generator<int, \DateTimeImmutable>
     */
    public static function runsAfter(\DateTimeImmutable $from): \Generator
    {
        $zone = new \DateTimeZone(self::TIME_ZONE);
        $utc = $from->setTimezone(new \DateTimeZone('UTC'));
        $hour = $utc->setTime((int) $utc->format('H'), 0);
        while (true) {
            $hour = $hour->modify('+1 hour');
            if (\in_array((int) $hour->setTimezone($zone)->format('G'), self::HOURS, true)) {
                yield $hour;
            }
        }
    }
}
