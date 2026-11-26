<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\LearnProductAliases;

use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Domain\Matching\ItemReader;
use App\Purchasing\Domain\Model\AliasKeys;
use App\Purchasing\Domain\Model\AliasKind;
use App\Purchasing\Domain\Model\ProductAlias;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Purchasing\Domain\Model\SellerUnit;
use App\Purchasing\Domain\Repository\ProductAliasRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Purchasing\Domain\Repository\SellerUnitRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Writes the aliases of confirmed items (ADR 0042 §2): one per key of each item, created or pointed to
 * the item's product, the last confirmation of a key winning, in one flush. It also learns the seller's
 * multiplier for the unit of an item whose name gives a size on a unit of unknown count (`CO`, `kart.`),
 * when the confirmed package size is a whole multiple of it (ADR 0043). Aliases and multipliers are the
 * only things a person's confirmation writes for matching, and matching never writes them.
 */
final readonly class LearnProductAliasesHandler implements CommandHandlerInterface
{
    public function __construct(
        private PurchasedItemRepositoryInterface $items,
        private ProductAliasRepositoryInterface $aliases,
        private SellerUnitRepositoryInterface $sellerUnits,
        private TenantContextInterface $tenantContext,
        private ClockInterface $clock,
    ) {
    }

    /** @throws \LogicException when the organization is not the one the job or request runs in */
    public function __invoke(LearnProductAliasesCommand $command): void
    {
        if ($this->tenantContext->getOrganization()->getId()->toRfc4122() !== $command->organizationId) {
            throw new \LogicException('Aliases are learned only in the organization the writer runs in.');
        }

        $byId = [];
        foreach ($this->items->findByIds(array_map(Uuid::fromString(...), array_values(array_filter($command->itemIds, Uuid::isValid(...))))) as $item) {
            $byId[$item->getId()->toRfc4122()] = $item;
        }

        $keysByItem = [];
        $wanted = [];
        foreach ($command->itemIds as $itemId) {
            $item = $byId[$itemId] ?? null;
            if (null === $item || PurchasedItemStatus::Confirmed !== $item->getStatus() || null === $item->getProduct()) {
                continue;
            }
            $keysByItem[$itemId] = AliasKeys::of($item);
            foreach ($keysByItem[$itemId] as $kind => $key) {
                $wanted[$kind.'#'.$key] = ['kind' => $kind, 'key' => $key];
            }
        }
        if ([] === $keysByItem) {
            return;
        }

        /** @var array<string, ProductAlias> $aliases */
        $aliases = [];
        foreach ($this->aliases->findByKeys(array_values($wanted)) as $alias) {
            $aliases[$alias->getKind()->value.'#'.$alias->getKey()] = $alias;
        }

        $now = $this->clock->now();
        $changed = [];
        foreach ($keysByItem as $itemId => $keys) {
            $item = $byId[$itemId];
            $product = $item->getProduct();
            if (null === $product) {
                continue;
            }
            foreach ($keys as $kind => $key) {
                $index = $kind.'#'.$key;
                if (isset($aliases[$index])) {
                    $aliases[$index]->repoint($product, $item->getPackageSize(), $now);
                } else {
                    $aliases[$index] = new ProductAlias(Uuid::v7(), AliasKind::from($kind), $key, $product, $item->getPackageSize(), $now);
                }
                $changed[$index] = $aliases[$index];
            }
        }

        $this->aliases->saveAll(array_values($changed));
        $this->learnUnitMultipliers($keysByItem, $byId);
    }

    /**
     * @param array<string, array<string, string>> $keysByItem the confirmed items, by id
     * @param array<string, PurchasedItem>         $byId
     */
    private function learnUnitMultipliers(array $keysByItem, array $byId): void
    {
        /** @var array<string, array{seller: string, unit: string, multiplier: int}> $learned */
        $learned = [];
        foreach (array_keys($keysByItem) as $itemId) {
            $item = $byId[$itemId];
            $unit = ItemReader::unitKeyOf($item->getUnit());
            $size = $item->getPackageSize();
            if (null === $unit || null === $size) {
                continue;
            }
            $multiplier = ItemReader::multiplierOf(ItemReader::read($item->getName(), $item->getUnit()), $size);
            if (null === $multiplier) {
                continue;
            }
            $seller = AliasKeys::sellerOf($item);
            $learned[$seller.'#'.$unit] = ['seller' => $seller, 'unit' => $unit, 'multiplier' => $multiplier];
        }
        if ([] === $learned) {
            return;
        }

        /** @var array<string, SellerUnit> $units */
        $units = [];
        foreach ($this->sellerUnits->findByPairs(array_values(array_map(static fn (array $l): array => ['seller' => $l['seller'], 'unit' => $l['unit']], $learned))) as $unit) {
            $units[$unit->getSellerKey().'#'.$unit->getUnitKey()] = $unit;
        }
        $now = $this->clock->now();
        $changed = [];
        foreach ($learned as $index => $l) {
            if (isset($units[$index])) {
                $units[$index]->change($l['multiplier'], $now);
            } else {
                $units[$index] = new SellerUnit(Uuid::v7(), $l['seller'], $l['unit'], $l['multiplier'], $now);
            }
            $changed[] = $units[$index];
        }
        $this->sellerUnits->saveAll($changed);
    }
}
