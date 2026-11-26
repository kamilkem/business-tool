<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Purchasing\Domain\Exception\ItemInterpreterUnavailableException;
use App\Purchasing\Domain\Matching\InterpretationConfidence;
use App\Purchasing\Domain\Matching\ItemInterpretation;
use App\Purchasing\Domain\Matching\ItemInterpreterInterface;
use App\Purchasing\Domain\Matching\ProductProposal;

/**
 * The item interpreter for handler, functional and API tests (adr/0042 §3): answers by an item's exact
 * name from a table the test sets up, can be made unavailable, and keeps what it was asked for assertions.
 * Items it has no answer for get none, like the real one when it finds no product.
 */
final class InMemoryItemInterpreter implements ItemInterpreterInterface
{
    public bool $available = true;

    public ?ItemInterpreterUnavailableException $failure = null;

    /** @var list<array{catalog: list<\App\Purchasing\Domain\Matching\CatalogProduct>, categories: list<\App\Purchasing\Domain\Matching\CatalogCategory>, items: list<\App\Purchasing\Domain\Matching\ItemToInterpret>}> */
    public array $calls = [];

    /** @var array<string, array{?string, InterpretationConfidence, ?string, ?ProductProposal}> item name => [product id, confidence, package size, proposal] */
    private array $answers = [];

    public function answer(string $itemName, ?string $productId, InterpretationConfidence $confidence = InterpretationConfidence::High, ?string $packageSize = null, ?ProductProposal $proposal = null): void
    {
        $this->answers[$itemName] = [$productId, $confidence, $packageSize, $proposal];
    }

    /** The model finds no product for the item and proposes one. */
    public function propose(string $itemName, ProductProposal $proposal): void
    {
        $this->answer($itemName, null, InterpretationConfidence::Low, null, $proposal);
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function interpret(array $catalog, array $categories, array $items): array
    {
        $this->calls[] = ['catalog' => $catalog, 'categories' => $categories, 'items' => $items];
        if (null !== $this->failure) {
            throw $this->failure;
        }

        $answers = [];
        foreach ($items as $item) {
            if (isset($this->answers[$item->name])) {
                [$productId, $confidence, $size, $proposal] = $this->answers[$item->name];
                $answers[] = new ItemInterpretation($item->key, $productId, $confidence, $size, $proposal);
            }
        }

        return $answers;
    }

    public function reset(): void
    {
        $this->available = true;
        $this->failure = null;
        $this->calls = [];
        $this->answers = [];
    }
}
