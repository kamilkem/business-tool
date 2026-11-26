<?php

declare(strict_types=1);

namespace App\Shared\Application\Job;

/**
 * Queues jobs on the `jobs` transport (ADR 0025 §2); the call returns once the job is queued.
 */
interface JobBusInterface
{
    /**
     * @param string|null $deduplicationKey while a job with this key is queued or running, another
     *                                      one with the same key is dropped (ADR 0025 §5)
     * @param int         $delaySeconds     the job is not handled before this many seconds have passed
     *                                      (ADR 0046 §2: a burst of changes collapses into one job
     *                                      that sees them all); 0 is as soon as the worker is free
     */
    public function dispatch(JobInterface $job, ?string $deduplicationKey = null, int $delaySeconds = 0): void;
}
