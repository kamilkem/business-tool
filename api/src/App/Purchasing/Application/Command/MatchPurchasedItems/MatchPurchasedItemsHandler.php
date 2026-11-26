<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\MatchPurchasedItems;

use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Domain\Exception\ItemInterpreterUnavailableException;
use App\Purchasing\Domain\Matching\CatalogProduct;
use App\Purchasing\Domain\Matching\ItemInterpretation;
use App\Purchasing\Domain\Matching\ItemInterpreterInterface;
use App\Purchasing\Domain\Matching\ItemReader;
use App\Purchasing\Domain\Matching\ItemToInterpret;
use App\Purchasing\Domain\Matching\MatchDecision;
use App\Purchasing\Domain\Model\AliasKeys;
use App\Purchasing\Domain\Model\AliasKind;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductAlias;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Purchasing\Domain\Repository\ProductAliasRepositoryInterface;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Links items to products, `assigned` or `suggested`, without touching what a person decided (ADR 0042 §3).
 *
 * 1. An alias the owner confirmed (GTIN, seller and code, seller and name, normalized name) decides first,
 *    strongest kind first: it costs nothing and is the owner's own word.
 * 2. What no alias knows goes to the model (when asked to and one is configured), once per distinct
 *    name, unit and code, in batches, with the organization's products. Its answer is checked against
 *    what the rules read from the item (MatchDecision).
 * 3. Items are saved in one flush. When the model cannot answer, what the aliases decided is saved and
 *    the exception is rethrown, so the job stops and the unanswered items stay for a later run.
 *
 * Items the model answered for, or found nothing for, are noted as matched, so it is not asked again.
 */
final readonly class MatchPurchasedItemsHandler implements CommandHandlerInterface
{
    /** Items per request to the model. */
    public const int BATCH_SIZE = 20;

    public function __construct(
        private PurchasedItemRepositoryInterface $items,
        private ProductRepositoryInterface $products,
        private ProductAliasRepositoryInterface $aliases,
        private ItemInterpreterInterface $interpreter,
        private TenantContextInterface $tenantContext,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws \LogicException                     when the organization is not the one the job runs in, or there are more items than MAX_ITEMS
     * @throws ItemInterpreterUnavailableException when the model could not answer for some items; the rest is saved
     */
    public function __invoke(MatchPurchasedItemsCommand $command): void
    {
        if ($this->tenantContext->getOrganization()->getId()->toRfc4122() !== $command->organizationId) {
            throw new \LogicException('Items are matched only in the organization the job runs in.');
        }
        if (\count($command->itemIds) > MatchPurchasedItemsCommand::MAX_ITEMS) {
            throw new \LogicException(\sprintf('At most %d items are matched at once.', MatchPurchasedItemsCommand::MAX_ITEMS));
        }

        $ids = array_map(Uuid::fromString(...), array_filter($command->itemIds, Uuid::isValid(...)));
        $items = array_values(array_filter($this->items->findByIds(array_values($ids)), static fn (PurchasedItem $item): bool => $item->isMatchable()));
        if ([] === $items) {
            return;
        }

        $now = $this->clock->now();
        $open = $this->matchByAliases($items, $now);
        $unavailable = null;
        $interpreted = 0;
        if ($command->useInterpreter && [] !== $open && $this->interpreter->isAvailable()) {
            try {
                $interpreted = $this->matchByInterpreter($open, $now);
            } catch (ItemInterpreterUnavailableException $e) {
                $unavailable = $e;
            }
        }

        $this->items->saveAll($items);
        $this->logger->info('Matched {items} purchased item(s); {open} had no alias, {interpreted} went to the model.', [
            'organization_id' => $command->organizationId,
            'items' => \count($items),
            'open' => \count($open),
            'interpreted' => $interpreted,
        ]);

        if (null !== $unavailable) {
            throw $unavailable;
        }
    }

    /**
     * Applies the aliases and returns the items no alias knew.
     *
     * @param list<PurchasedItem> $items
     *
     * @return list<PurchasedItem>
     */
    private function matchByAliases(array $items, \DateTimeImmutable $now): array
    {
        $keysByItem = [];
        $wanted = [];
        foreach ($items as $item) {
            $keys = AliasKeys::of($item);
            $keysByItem[$item->getId()->toRfc4122()] = $keys;
            foreach ($keys as $kind => $key) {
                $wanted[$kind.'#'.$key] = ['kind' => $kind, 'key' => $key];
            }
        }
        $found = [];
        foreach ($this->aliases->findByKeys(array_values($wanted)) as $alias) {
            $found[$alias->getKind()->value.'#'.$alias->getKey()] = $alias;
        }

        $open = [];
        foreach ($items as $item) {
            /** @var list<ProductAlias> $hits strongest kind first: AliasKeys::of() lists them so */
            $hits = [];
            foreach ($keysByItem[$item->getId()->toRfc4122()] as $kind => $key) {
                if (isset($found[$kind.'#'.$key])) {
                    $hits[] = $found[$kind.'#'.$key];
                }
            }
            if ([] === $hits) {
                $open[] = $item;
                continue;
            }

            $strongest = $hits[0];
            $conflict = false;
            foreach ($hits as $hit) {
                $conflict = $conflict || !$hit->getProduct()->getId()->equals($strongest->getProduct()->getId());
            }
            $decision = MatchDecision::fromAlias($strongest, $conflict, ItemReader::read($item->getName(), $item->getUnit()));
            $item->match($decision->product, $decision->packageSize, $decision->status, $now);
        }

        return $open;
    }

    /**
     * Puts the open items to the model and applies its answers; returns how many items were put to it.
     *
     * @param list<PurchasedItem> $open
     *
     * @throws ItemInterpreterUnavailableException
     */
    private function matchByInterpreter(array $open, \DateTimeImmutable $now): int
    {
        /** @var array<string, Product> $products */
        $products = [];
        $catalog = [];
        foreach ($this->products->search(null, null) as $product) {
            $products[$product->getId()->toRfc4122()] = $product;
            $catalog[] = new CatalogProduct($product->getId()->toRfc4122(), $product->getName(), $product->getMeasure()->value, $product->getCategory()?->getName());
        }
        if ([] === $catalog) {
            return 0;
        }

        // One question per distinct name, unit and code: the same row on twenty invoices is asked once.
        /** @var array<string, list<PurchasedItem>> $groups */
        $groups = [];
        /** @var array<string, ItemToInterpret> $questions */
        $questions = [];
        foreach ($open as $item) {
            $key = sha1(implode("\0", [$item->getName(), $item->getUnit() ?? '', $item->getSellerCode() ?? '']));
            $groups[$key][] = $item;
            $questions[$key] ??= new ItemToInterpret($key, $item->getName(), $item->getUnit(), $item->getSellerCode());
        }

        $asked = 0;
        foreach (array_chunk($questions, self::BATCH_SIZE) as $batch) {
            /** @var non-empty-list<ItemToInterpret> $batch */
            /** @var non-empty-list<CatalogProduct> $catalog */
            $answers = [];
            foreach ($this->interpreter->interpret($catalog, $batch) as $answer) {
                $answers[$answer->key] = $answer;
            }
            foreach ($batch as $question) {
                $asked += \count($groups[$question->key]);
                foreach ($groups[$question->key] as $item) {
                    $this->apply($item, $answers[$question->key] ?? null, $products, $now);
                }
            }
        }

        return $asked;
    }

    /** @param array<string, Product> $products */
    private function apply(PurchasedItem $item, ?ItemInterpretation $answer, array $products, \DateTimeImmutable $now): void
    {
        $product = null === $answer || null === $answer->productId ? null : ($products[$answer->productId] ?? null);
        $decision = null === $answer || null === $product
            ? null
            : MatchDecision::fromInterpretation($product, $answer, ItemReader::read($item->getName(), $item->getUnit()));

        if (null === $decision) {
            $item->match(null, null, PurchasedItemStatus::Unassigned, $now);

            return;
        }
        $item->match($decision->product, $decision->packageSize, $decision->status, $now);
    }
}
