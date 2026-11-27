<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\AssignPurchasedItems;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager links several purchased items to one product, for the review queue's bulk
 * action: all or nothing. Each item becomes `confirmed`. With a package size, every item gets it, and the bundle
 * count that goes with it (null: no bundle); without, each keeps its own size and count (ADR 0041 §3, ADR 0048).
 */
final readonly class AssignPurchasedItemsCommand implements CommandInterface
{
    public const int MAX_ITEMS = 500;

    /** @param list<string> $itemIds at most MAX_ITEMS */
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public array $itemIds,
        public string $productId,
        public ?string $packageSize,
        public ?int $bundleCount = null,
    ) {
    }
}
