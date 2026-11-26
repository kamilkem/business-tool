<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus\Job;

use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Application\Job\JobInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Sends jobs to the `jobs` transport through `messenger.bus.job` (ADR 0025 §2). A deduplication key
 * becomes a DeduplicateStamp: Messenger holds a lock with that key (DoctrineDbalStore, `lock_keys`)
 * from dispatch until the job was handled, and drops a second job with the same key meanwhile
 * (ADR 0025 §5).
 */
final readonly class MessengerJobBus implements JobBusInterface
{
    /**
     * How long the deduplication lock lives if the job is never handled: a queued job may wait for the
     * next hourly tick, then run for its time budget (KSeF: 45 minutes); a delayed job (a few minutes) fits.
     */
    public const float DEDUPLICATION_TTL_SECONDS = 7200.0;

    public function __construct(
        #[Autowire(service: 'messenger.bus.job')]
        private MessageBusInterface $messageBus,
    ) {
    }

    public function dispatch(JobInterface $job, ?string $deduplicationKey = null, int $delaySeconds = 0): void
    {
        $stamps = null === $deduplicationKey ? [] : [new DeduplicateStamp($deduplicationKey, self::DEDUPLICATION_TTL_SECONDS)];
        if ($delaySeconds > 0) {
            $stamps[] = new DelayStamp($delaySeconds * 1000);
        }

        $this->messageBus->dispatch($job, $stamps);
    }
}
