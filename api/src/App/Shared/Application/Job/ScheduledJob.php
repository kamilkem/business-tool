<?php

declare(strict_types=1);

namespace App\Shared\Application\Job;

/**
 * A schedule entry's message (ADR 0025 §1): when the scheduler's tick fires, the job is queued on the
 * job bus with its deduplication key, so the work runs on the jobs worker and never in the scheduler's
 * consumer. Schedules in `Ui\Scheduler\` wrap each job in one.
 */
final readonly class ScheduledJob
{
    public function __construct(
        public JobInterface $job,
        public ?string $deduplicationKey = null,
    ) {
    }
}
