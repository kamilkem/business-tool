<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\BackfillProductAliases;

use App\Shared\Application\Command\CommandInterface;

/**
 * Creates the aliases that items confirmed before aliases were learned would have taught (ADR 0045 §3), as
 * the backfill job: it has no actor and is never sent from Ui. Only keys without an alias get one, so what
 * a later confirmation taught is kept, and no item or link is touched.
 */
final readonly class BackfillProductAliasesCommand implements CommandInterface
{
    public const int MAX_ITEMS = 500;

    /** @param list<string> $itemIds at most MAX_ITEMS, newest first: of two items with a key, the first decides */
    public function __construct(
        public string $organizationId,
        public array $itemIds,
    ) {
    }
}
