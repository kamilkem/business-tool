<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ui\Scheduler;

use App\Ksef\Application\Job\StartKsefImports\StartKsefImportsJob;
use App\Shared\Application\Job\ScheduledJob;
use App\TimeTracking\Application\Job\StartLongShiftReports\StartLongShiftReportsJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Ui\Scheduler\DefaultSchedule;

/** The `default` schedule's entries and their next run times, across DST changes (ADR 0025 §7). */
#[CoversClass(DefaultSchedule::class)]
final class DefaultScheduleTest extends TestCase
{
    public function test_it_queues_the_ksef_and_the_long_shift_fan_outs_and_nothing_else(): void
    {
        $messages = [];
        foreach ($this->entries() as $entry) {
            array_push($messages, ...iterator_to_array($entry->getMessages(new MessageContext('default', $entry->getId(), $entry->getTrigger(), new \DateTimeImmutable())), false));
        }

        self::assertEquals([new ScheduledJob(new StartKsefImportsJob()), new ScheduledJob(new StartLongShiftReportsJob())], $messages);
    }

    public function test_it_reports_long_shifts_hourly_on_the_hour_also_across_a_dst_change(): void
    {
        $trigger = $this->entries()[1]->getTrigger();

        $runs = [];
        $run = new \DateTimeImmutable('2026-10-25 00:30:00 UTC');
        for ($i = 0; $i < 4; ++$i) {
            $run = $trigger->getNextRunDate($run) ?? self::fail('Next run expected.');
            $runs[] = $run->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i P');
        }

        // Warsaw's 02:00-03:00 happens twice that night; in UTC the runs stay one hour apart.
        self::assertSame(['2026-10-25 01:00 +00:00', '2026-10-25 02:00 +00:00', '2026-10-25 03:00 +00:00', '2026-10-25 04:00 +00:00'], $runs);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function runs(): iterable
    {
        yield 'an ordinary night: hourly from 20:00 to 06:00 in Warsaw' => ['2026-11-20 12:00:00 UTC', [
            '2026-11-20 20:00 +01:00', '2026-11-20 21:00 +01:00', '2026-11-20 22:00 +01:00', '2026-11-20 23:00 +01:00',
            '2026-11-21 00:00 +01:00', '2026-11-21 01:00 +01:00', '2026-11-21 02:00 +01:00', '2026-11-21 03:00 +01:00',
            '2026-11-21 04:00 +01:00', '2026-11-21 05:00 +01:00', '2026-11-21 06:00 +01:00', '2026-11-21 20:00 +01:00',
        ]];
        // 02:00 happens twice; the runs stay one hour apart.
        yield 'the night summer time ends' => ['2026-10-24 10:00:00 UTC', [
            '2026-10-24 20:00 +02:00', '2026-10-24 21:00 +02:00', '2026-10-24 22:00 +02:00', '2026-10-24 23:00 +02:00',
            '2026-10-25 00:00 +02:00', '2026-10-25 01:00 +02:00', '2026-10-25 02:00 +02:00', '2026-10-25 02:00 +01:00',
            '2026-10-25 03:00 +01:00', '2026-10-25 04:00 +01:00', '2026-10-25 05:00 +01:00', '2026-10-25 06:00 +01:00',
        ]];
        // 02:00 does not exist; the runs stay one hour apart.
        yield 'the night summer time starts' => ['2026-03-28 11:00:00 UTC', [
            '2026-03-28 20:00 +01:00', '2026-03-28 21:00 +01:00', '2026-03-28 22:00 +01:00', '2026-03-28 23:00 +01:00',
            '2026-03-29 00:00 +01:00', '2026-03-29 01:00 +01:00', '2026-03-29 03:00 +02:00', '2026-03-29 04:00 +02:00',
            '2026-03-29 05:00 +02:00', '2026-03-29 06:00 +02:00', '2026-03-29 20:00 +02:00', '2026-03-29 21:00 +02:00',
        ]];
    }

    /** @param list<string> $expected */
    #[DataProvider('runs')]
    public function test_it_runs_the_ksef_import_hourly_at_night_in_polish_time(string $from, array $expected): void
    {
        $trigger = $this->ksefImport()->getTrigger();

        $runs = [];
        $run = new \DateTimeImmutable($from);
        foreach ($expected as $_) {
            $run = $trigger->getNextRunDate($run) ?? self::fail('Next run expected.');
            $runs[] = $run->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i P');
        }

        self::assertSame($expected, $runs);
    }

    private function ksefImport(): RecurringMessage
    {
        return $this->entries()[0];
    }

    /** @return list<RecurringMessage> */
    private function entries(): array
    {
        $schedule = (new DefaultSchedule(new ArrayAdapter(), new LockFactory(new InMemoryStore())))->getSchedule();
        $messages = array_values($schedule->getRecurringMessages());
        self::assertCount(2, $messages);

        return $messages;
    }
}
