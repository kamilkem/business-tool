<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\MatchPurchasedItems;

use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Domain\Exception\ItemInterpreterUnavailableException;
use App\Purchasing\Domain\Matching\CatalogCategory;
use App\Purchasing\Domain\Matching\CatalogProduct;
use App\Purchasing\Domain\Matching\InterpretationConfidence;
use App\Purchasing\Domain\Matching\ItemInterpretation;
use App\Purchasing\Domain\Matching\ItemInterpreterInterface;
use App\Purchasing\Domain\Matching\ItemReader;
use App\Purchasing\Domain\Matching\ItemReading;
use App\Purchasing\Domain\Matching\ItemToInterpret;
use App\Purchasing\Domain\Matching\MatchDecision;
use App\Purchasing\Domain\Model\AliasKeys;
use App\Purchasing\Domain\Model\AliasKind;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductAlias;
use App\Purchasing\Domain\Model\ProductCategory;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Purchasing\Domain\Repository\ProductAliasRepositoryInterface;
use App\Purchasing\Domain\Repository\ProductCategoryRepositoryInterface;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Purchasing\Domain\Repository\SellerUnitRepositoryInterface;
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
 *    A size read on a unit of unknown count (`CO`, `kart.`) is multiplied by the seller's multiplier for that
 *    unit when the owner confirmed one (ADR 0043); without it the item is only ever `suggested`.
 * 2. What no alias knows goes to the model (when asked to and one is configured), once per distinct
 *    name, unit and code, in batches, with the organization's products. Its answer is checked against
 *    what the rules read from the item (MatchDecision).
 * 3. Items are saved in one flush. When the model cannot answer, what the aliases decided is saved and
 *    the exception is rethrown, so the job stops and the unanswered items stay for a later run.
 *
 * An `assigned` decision whose price per base unit is far from the median of the product's confirmed items
 * is lowered to `suggested` (ADR 0045 §2), whatever decided it.
 *
 * Items the model answered for, or found nothing for, are noted as matched, so it is not asked again.
 *
 * For an item no product is, the model may also propose one (name, measure, a category of the tree);
 * the proposal is stored on the item for a person to accept (ADR 0044). A proposed name that is the name of a
 * product that exists is a match to it, `suggested`, not a proposal. Nothing here creates a product.
 */
final readonly class MatchPurchasedItemsHandler implements CommandHandlerInterface
{
    /** Items per request to the model. */
    public const int BATCH_SIZE = 20;

    public function __construct(
        private PurchasedItemRepositoryInterface $items,
        private ProductRepositoryInterface $products,
        private ProductCategoryRepositoryInterface $categories,
        private ProductAliasRepositoryInterface $aliases,
        private SellerUnitRepositoryInterface $sellerUnits,
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
        $multipliers = $this->unitMultipliers($items);
        $prices = new PriceSamples($this->items);
        $open = $this->matchByAliases($items, $multipliers, $prices, $now);
        $unavailable = null;
        $interpreted = 0;
        if ($command->useInterpreter && [] !== $open && $this->interpreter->isAvailable()) {
            try {
                $interpreted = $this->matchByInterpreter($open, $multipliers, $prices, $now);
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
     * @param array<string, int>  $multipliers the sellers' multipliers by seller and unit
     *
     * @return list<PurchasedItem>
     */
    private function matchByAliases(array $items, array $multipliers, PriceSamples $prices, \DateTimeImmutable $now): array
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
            $decision = $prices->check(MatchDecision::fromAlias($strongest, $conflict, $this->readingOf($item, $multipliers)), $item);
            $item->match($decision->product, $decision->packageSize, $decision->status, $now, $decision->source, $decision->caveat);
        }

        return $open;
    }

    /**
     * Puts the open items to the model and applies its answers; returns how many items were put to it.
     *
     * @param list<PurchasedItem> $open
     * @param array<string, int>  $multipliers
     *
     * @throws ItemInterpreterUnavailableException
     */
    private function matchByInterpreter(array $open, array $multipliers, PriceSamples $prices, \DateTimeImmutable $now): int
    {
        /** @var array<string, Product> $products */
        $products = [];
        $catalog = [];
        foreach ($this->products->search(null, null) as $product) {
            $products[$product->getId()->toRfc4122()] = $product;
            $catalog[] = new CatalogProduct($product->getId()->toRfc4122(), $product->getName(), $product->getMeasure()->value, $product->getCategory()?->getName());
        }
        /** @var array<string, ProductCategory> $categoriesById */
        $categoriesById = [];
        foreach ($this->categories->findAll() as $category) {
            $categoriesById[$category->getId()->toRfc4122()] = $category;
        }
        $choices = $this->categoryChoices($categoriesById);

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
            $answers = [];
            foreach ($this->interpreter->interpret($catalog, $choices, $batch) as $answer) {
                $answers[$answer->key] = $answer;
            }
            foreach ($batch as $question) {
                $asked += \count($groups[$question->key]);
                foreach ($groups[$question->key] as $item) {
                    $this->apply($item, $answers[$question->key] ?? null, $products, $categoriesById, $multipliers, $prices, $now);
                }
            }
        }

        return $asked;
    }

    /**
     * @param array<string, Product>         $products
     * @param array<string, ProductCategory> $categories
     * @param array<string, int>             $multipliers
     */
    private function apply(PurchasedItem $item, ?ItemInterpretation $answer, array $products, array $categories, array $multipliers, PriceSamples $prices, \DateTimeImmutable $now): void
    {
        $product = null === $answer || null === $answer->productId ? null : ($products[$answer->productId] ?? null);
        $decision = null === $answer || null === $product
            ? null
            : MatchDecision::fromInterpretation($product, $answer, $this->readingOf($item, $multipliers));

        if (null === $decision && null !== $answer?->proposal) {
            // No product of the catalog, but the proposed name is one that exists: that is a match to it, for a person to confirm.
            $named = $this->productNamed($answer->proposal->name, $products);
            if (null !== $named) {
                $decision = MatchDecision::fromInterpretation($named, new ItemInterpretation($answer->key, $named->getId()->toRfc4122(), InterpretationConfidence::Medium, $answer->packageSize), $this->readingOf($item, $multipliers), fromProposedName: true);
            }
        }

        if (null === $decision) {
            $item->match(null, null, PurchasedItemStatus::Unassigned, $now);
            $proposal = $answer?->proposal;
            $item->propose($proposal?->name, $proposal?->measure, null === $proposal?->categoryId ? null : ($categories[$proposal->categoryId] ?? null));

            return;
        }
        $decision = $prices->check($decision, $item);
        $item->match($decision->product, $decision->packageSize, $decision->status, $now, $decision->source, $decision->caveat);
    }

    /**
     * @param array<string, Product> $products
     */
    private function productNamed(string $name, array $products): ?Product
    {
        $key = Product::nameKeyOf($name);
        foreach ($products as $product) {
            if (Product::nameKeyOf($product->getName()) === $key) {
                return $product;
            }
        }

        return null;
    }

    /**
     * The categories as paths of names, `Alcohol > Spirits`, for the model to choose from by id.
     *
     * @param array<string, ProductCategory> $categories
     *
     * @return list<CatalogCategory>
     */
    private function categoryChoices(array $categories): array
    {
        $choices = [];
        foreach ($categories as $id => $category) {
            $path = [$category->getName()];
            for ($parent = $category->getParent(), $depth = 0; null !== $parent && $depth < 32; $parent = $parent->getParent(), ++$depth) {
                array_unshift($path, $parent->getName());
            }
            $choices[] = new CatalogCategory($id, implode(' > ', $path));
        }
        usort($choices, static fn (CatalogCategory $a, CatalogCategory $b): int => $a->path <=> $b->path);

        return $choices;
    }

    /**
     * The seller's multipliers for the units of these items, by seller key and unit key (ADR 0043).
     *
     * @param list<PurchasedItem> $items
     *
     * @return array<string, int>
     */
    private function unitMultipliers(array $items): array
    {
        $pairs = [];
        foreach ($items as $item) {
            $unit = ItemReader::unitKeyOf($item->getUnit());
            if (null !== $unit) {
                $seller = AliasKeys::sellerOf($item);
                $pairs[$seller.'#'.$unit] = ['seller' => $seller, 'unit' => $unit];
            }
        }
        $multipliers = [];
        foreach ($this->sellerUnits->findByPairs(array_values($pairs)) as $unit) {
            $multipliers[$unit->getSellerKey().'#'.$unit->getUnitKey()] = $unit->getMultiplier();
        }

        return $multipliers;
    }

    /** @param array<string, int> $multipliers */
    private function readingOf(PurchasedItem $item, array $multipliers): ItemReading
    {
        $reading = ItemReader::read($item->getName(), $item->getUnit());
        $unit = ItemReader::unitKeyOf($item->getUnit());
        $multiplier = null === $unit ? null : ($multipliers[AliasKeys::sellerOf($item).'#'.$unit] ?? null);

        return null === $multiplier ? $reading : ItemReader::withUnitMultiplier($reading, $multiplier);
    }
}
