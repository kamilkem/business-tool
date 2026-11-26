<?php

declare(strict_types=1);

namespace App\Purchasing\Application\EventHandler;

use App\Purchasing\Application\Job\MatchPurchasedItems\MatchPurchasedItemsJob;
use App\Purchasing\Domain\Event\PurchasedItemsRecorded;
use App\Purchasing\Domain\Matching\MatchScope;
use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;

/**
 * New purchased items are matched by a job, not here: the lookups and the model do not belong in the
 * importer's job that is recording them (ADR 0042 §7). The job takes the items the model never saw, so
 * one queued job covers every item recorded before it starts.
 */
final readonly class QueueMatchingOnPurchasedItemsRecorded implements EventHandlerInterface
{
    public function __construct(private JobBusInterface $jobBus)
    {
    }

    public function __invoke(PurchasedItemsRecorded $event): void
    {
        $this->jobBus->dispatch(
            new MatchPurchasedItemsJob($event->organizationId, MatchScope::NotYetMatched->value, true),
            MatchPurchasedItemsJob::deduplicationKey($event->organizationId, MatchScope::NotYetMatched->value, true),
        );
    }
}
