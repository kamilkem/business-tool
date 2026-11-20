<?php

declare(strict_types=1);

namespace Ui\Scheduler;

use App\Ksef\Application\Job\StartKsefImports\StartKsefImportsJob;
use App\Shared\Application\Job\ScheduledJob;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The `default` schedule (ADR 0025 §1), consumed by the `worker` as `scheduler_default`. Each entry
 * only queues a job (ScheduledJob); the work runs on the `jobs-worker`. Stateful (the last run in
 * PostgreSQL, so a tick missed during a deploy fires once afterwards) and locked (one consumer fires a
 * tick). Every entry names its time zone. `bin/console debug:scheduler` lists it.
 */
#[AsSchedule('default')]
final readonly class DefaultSchedule implements ScheduleProviderInterface
{
    /** KSeF import: hourly from 20:00 to 06:00 Polish time, when KSeF's limits are higher (ADR 0023 §5). */
    public const string KSEF_IMPORT_CRON = '0 20-23,0-6 * * *';
    public const string KSEF_IMPORT_TIME_ZONE = 'Europe/Warsaw';

    public function __construct(
        #[Autowire(service: 'cache.scheduler')]
        private CacheInterface $cache,
        private LockFactory $lockFactory,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->lock($this->lockFactory->createLock('scheduler-default'))
            ->add(RecurringMessage::cron(self::KSEF_IMPORT_CRON, new ScheduledJob(new StartKsefImportsJob()), self::KSEF_IMPORT_TIME_ZONE));
    }
}
