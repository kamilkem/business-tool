<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * A person linked items to a product, so they are `confirmed` (ADR 0042 §4, §7): the aliases are
 * learned from them and the other items are matched again. Primitives only.
 */
final readonly class PurchasedItemsConfirmed implements EventInterface
{
    /** @param non-empty-list<string> $itemIds */
    public function __construct(
        public string $organizationId,
        public array $itemIds,
    ) {
    }
}
