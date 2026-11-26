<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Matching;

use App\Purchasing\Domain\Exception\ItemInterpreterUnavailableException;

/**
 * The language model that says which catalog product an invoice item is and what package size it has
 * (ADR 0042 §3). The adapter is on the Claude API (Infrastructure/Anthropic); tests and CI use an
 * in-memory one and never reach the network.
 */
interface ItemInterpreterInterface
{
    /** Whether an interpreter is configured at all; when not, interpret() is never called. */
    public function isAvailable(): bool;

    /**
     * Answers for the items, in any order; an item left out got no answer. A product id that is not in
     * the catalog is no answer either.
     *
     * @param non-empty-list<CatalogProduct> $catalog
     * @param non-empty-list<ItemToInterpret> $items
     *
     * @return list<ItemInterpretation>
     *
     * @throws ItemInterpreterUnavailableException when the provider cannot answer now (down, rate limited, rejected credentials, an unreadable answer)
     */
    public function interpret(array $catalog, array $items): array;
}
