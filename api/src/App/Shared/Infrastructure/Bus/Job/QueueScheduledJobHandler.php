<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus\Job;

use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Application\Job\ScheduledJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Queues a scheduled job (ADR 0025 §1). The `worker` receives schedule ticks from `scheduler_default`
 * and dispatches them on the default bus (`messenger.bus.command`), so this handler lives there; it
 * only enqueues and never runs the job itself. Commands are still never routed to a transport.
 */
#[AsMessageHandler(bus: 'messenger.bus.command')]
final readonly class QueueScheduledJobHandler
{
    public function __construct(private JobBusInterface $jobBus)
    {
    }

    public function __invoke(ScheduledJob $message): void
    {
        $this->jobBus->dispatch($message->job, $message->deduplicationKey);
    }
}
