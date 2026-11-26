<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\LearnProductAliases;

use App\Shared\Application\Command\CommandInterface;

/**
 * Remembers what a person confirmed (ADR 0042 §2): for each of the items, now `confirmed` with a
 * product, its keys point to that product from now on. Sent by the event handler of
 * PurchasedItemsConfirmed, never from Ui; items that are not confirmed are skipped.
 */
final readonly class LearnProductAliasesCommand implements CommandInterface
{
    /** @param list<string> $itemIds in the order the person confirmed them: the last of a key wins */
    public function __construct(
        public string $organizationId,
        public array $itemIds,
    ) {
    }
}
