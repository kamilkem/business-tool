<?php

declare(strict_types=1);

namespace App\Purchasing\Application\EventHandler;

use App\Purchasing\Application\Job\MatchPurchasedItems\MatchPurchasedItemsJob;
use App\Purchasing\Domain\Event\ProductCreated;
use App\Purchasing\Domain\Matching\MatchScope;
use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;
use Psr\Clock\ClockInterface;

/**
 * A new product is a new thing the model can match to, so (ADR 0044 §6, ADR 0046 §2)
 * - the items it never went through are matched at once: the same job and key as for newly recorded items, so a
 *   burst of products created in a row is one queued job while it has not started;
 * - the unassigned items it found nothing for are put to it once more, five minutes later, so that every product
 *   created meanwhile is in the catalog it sees. That job has its own key: the burst adds nothing to it, and it
 *   asks only the items the model has not been through since this product was created (ADR 0046 §3).
 */
final readonly class QueueMatchingOnProductCreated implements EventHandlerInterface
{
    public function __construct(
        private JobBusInterface $jobBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ProductCreated $event): void
    {
        $this->jobBus->dispatch(
            new MatchPurchasedItemsJob($event->organizationId, MatchScope::NotYetMatched->value, true),
            MatchPurchasedItemsJob::deduplicationKey($event->organizationId, MatchScope::NotYetMatched->value, true),
        );
        $this->jobBus->dispatch(
            MatchPurchasedItemsJob::afterCatalogChange($event->organizationId, $this->clock->now()),
            MatchPurchasedItemsJob::afterCatalogChangeDeduplicationKey($event->organizationId),
            MatchPurchasedItemsJob::SETTLE_DELAY_SECONDS,
        );
    }
}
