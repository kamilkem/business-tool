<?php

declare(strict_types=1);

namespace App\Purchasing\Application\EventHandler;

use App\Purchasing\Application\Job\MatchPurchasedItems\MatchPurchasedItemsJob;
use App\Purchasing\Domain\Event\ProductChanged;
use App\Purchasing\Domain\Matching\MatchScope;
use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;
use Psr\Clock\ClockInterface;

/**
 * A product renamed, or with another measure, is another thing to the model than it was (ADR 0046 §1), so the
 * items are put to it as for a created product: those it never went through at once, the unassigned ones it found
 * nothing for once, five minutes later and only if the model has not been through them since (ADR 0046 §2, §3).
 */
final readonly class QueueMatchingOnProductChanged implements EventHandlerInterface
{
    public function __construct(
        private JobBusInterface $jobBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ProductChanged $event): void
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
