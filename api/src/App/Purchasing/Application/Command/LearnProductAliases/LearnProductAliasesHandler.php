<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\LearnProductAliases;

use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Domain\Model\AliasKeys;
use App\Purchasing\Domain\Model\AliasKind;
use App\Purchasing\Domain\Model\ProductAlias;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Purchasing\Domain\Repository\ProductAliasRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Writes the aliases of confirmed items (ADR 0042 §2): one per key of each item, created or pointed to
 * the item's product, the last confirmation of a key winning, in one flush. Aliases are the one thing
 * only a person's confirmation writes.
 */
final readonly class LearnProductAliasesHandler implements CommandHandlerInterface
{
    public function __construct(
        private PurchasedItemRepositoryInterface $items,
        private ProductAliasRepositoryInterface $aliases,
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
    }
}
