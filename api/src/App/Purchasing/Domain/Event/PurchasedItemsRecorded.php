<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/** Purchasing recorded new purchased items (ADR 0042 §7): matching is queued for them. Primitives only. */
final readonly class PurchasedItemsRecorded implements EventInterface
{
    /** @param non-empty-list<string> $itemIds */
    public function __construct(
        public string $organizationId,
        public array $itemIds,
    ) {
    }
}
