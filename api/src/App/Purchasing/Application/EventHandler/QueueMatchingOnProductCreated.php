<?php

declare(strict_types=1);

namespace App\Purchasing\Application\EventHandler;

use App\Purchasing\Application\Job\MatchPurchasedItems\MatchPurchasedItemsJob;
use App\Purchasing\Domain\Event\ProductCreated;
use App\Purchasing\Domain\Matching\MatchScope;
use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;

/**
 * A new product is a new thing the model can match to, so the items it never went through are matched
 * again, without the console command (ADR 0044). The same job and key as for newly recorded items: a burst of
 * products created in a row is one queued job, as long as it has not started. The unassigned items the
 * model found nothing for are not asked again here; that is `app:purchasing:match-items --again`.
 */
final readonly class QueueMatchingOnProductCreated implements EventHandlerInterface
{
    public function __construct(private JobBusInterface $jobBus)
    {
    }

    public function __invoke(ProductCreated $event): void
    {
        $this->jobBus->dispatch(
            new MatchPurchasedItemsJob($event->organizationId, MatchScope::NotYetMatched->value, true),
            MatchPurchasedItemsJob::deduplicationKey($event->organizationId, MatchScope::NotYetMatched->value, true),
        );
    }
}
