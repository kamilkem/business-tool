<?php

declare(strict_types=1);

namespace App\Purchasing\Application\EventHandler;

use App\Purchasing\Application\Job\MatchPurchasedItems\MatchPurchasedItemsJob;
use App\Purchasing\Domain\Event\PurchasedItemsConfirmed;
use App\Purchasing\Domain\Matching\MatchScope;
use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;

/**
 * A new or changed alias settles the items that share its key, so the organization's matchable items
 * are matched again by the aliases alone, which costs no call to the model (ADR 0042 §7). A wrong
 * match that a person corrects thus corrects every item with the same key.
 */
final readonly class QueueAliasMatchingOnPurchasedItemsConfirmed implements EventHandlerInterface
{
    public function __construct(private JobBusInterface $jobBus)
    {
    }

    public function __invoke(PurchasedItemsConfirmed $event): void
    {
        $this->jobBus->dispatch(
            new MatchPurchasedItemsJob($event->organizationId, MatchScope::AllMatchable->value, false),
            MatchPurchasedItemsJob::deduplicationKey($event->organizationId, MatchScope::AllMatchable->value, false),
        );
    }
}
