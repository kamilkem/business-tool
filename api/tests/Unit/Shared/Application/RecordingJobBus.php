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

    public function dispatch(JobInterface $job, ?string $deduplicationKey = null): void
    {
        $this->dispatched[] = ['job' => $job, 'deduplicationKey' => $deduplicationKey];
    }
}
