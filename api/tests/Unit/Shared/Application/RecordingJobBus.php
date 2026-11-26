<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application;

use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Application\Job\JobInterface;

/** Fake bus: keeps queued jobs with their deduplication keys for assertions and runs none. */
final class RecordingJobBus implements JobBusInterface
{
    /** @var list<array{job: JobInterface, deduplicationKey: string|null}> */
    public array $dispatched = [];

    /** @var list<int> the delay in seconds of each dispatched job, in the same order */
    public array $delays = [];

    public function dispatch(JobInterface $job, ?string $deduplicationKey = null, int $delaySeconds = 0): void
    {
        $this->dispatched[] = ['job' => $job, 'deduplicationKey' => $deduplicationKey];
        $this->delays[] = $delaySeconds;
    }
}
