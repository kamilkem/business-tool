<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Purchasing\Domain\Exception\ItemInterpreterUnavailableException;
use App\Purchasing\Domain\Matching\InterpretationConfidence;
use App\Purchasing\Domain\Matching\ItemInterpretation;
use App\Purchasing\Domain\Matching\ItemInterpreterInterface;

/**
 * The item interpreter for handler, functional and API tests (adr/0042 §3): answers by an item's exact
 * name from a table the test sets up, can be made unavailable, and keeps what it was asked for assertions.
 * Items it has no answer for get none, like the real one when it finds no product.
 */
final class InMemoryItemInterpreter implements ItemInterpreterInterface
{
    public bool $available = true;

    public ?ItemInterpreterUnavailableException $failure = null;

    /** @var list<array{catalog: list<\App\Purchasing\Domain\Matching\CatalogProduct>, items: list<\App\Purchasing\Domain\Matching\ItemToInterpret>}> */
    public array $calls = [];

    /** @var array<string, array{?string, InterpretationConfidence, ?string}> item name => [product id, confidence, package size] */
    private array $answers = [];

    public function answer(string $itemName, ?string $productId, InterpretationConfidence $confidence = InterpretationConfidence::High, ?string $packageSize = null): void
    {
        $this->answers[$itemName] = [$productId, $confidence, $packageSize];
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function interpret(array $catalog, array $items): array
    {
        $this->calls[] = ['catalog' => $catalog, 'items' => $items];
        if (null !== $this->failure) {
            throw $this->failure;
        }

        $answers = [];
        foreach ($items as $item) {
            if (isset($this->answers[$item->name])) {
                [$productId, $confidence, $size] = $this->answers[$item->name];
                $answers[] = new ItemInterpretation($item->key, $productId, $confidence, $size);
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
