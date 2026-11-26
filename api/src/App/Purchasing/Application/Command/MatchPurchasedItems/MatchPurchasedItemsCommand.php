<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\MatchPurchasedItems;

use App\Shared\Application\Command\CommandInterface;

/**
 * Matches items of the current organization to its products (ADR 0042 §3), as the matching job: it has
 * no actor and is never sent from Ui. Items that are `confirmed` or that a person took off their
 * product are skipped. With `$useInterpreter` the items no alias knows are put to the model; without
 * it only the aliases decide (the re-match after a person's confirmation, which costs nothing).
 */
final readonly class MatchPurchasedItemsCommand implements CommandInterface
{
    public const int MAX_ITEMS = 500;

    /** @param list<string> $itemIds at most MAX_ITEMS */
    public function __construct(
        public string $organizationId,
        public array $itemIds,
        public bool $useInterpreter,
    ) {
    }
}
