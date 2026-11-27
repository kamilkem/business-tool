<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\BackfillProductAliases;

use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Domain\Model\AliasKeys;
use App\Purchasing\Domain\Model\AliasKind;
use App\Purchasing\Domain\Model\ProductAlias;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Purchasing\Domain\Repository\ProductAliasRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Learns aliases from items a person confirmed before aliases existed (ADR 0045 §3), like
 * LearnProductAliasesHandler but only for keys that have none: an alias that exists was taught by a later
 * confirmation, or by an earlier page of the backfill from a newer item, and is the better word. Items are
 * only read; no link, status or package size changes, and no seller multiplier is learned (a later
 * confirmation does that). In one flush.
 */
final readonly class BackfillProductAliasesHandler implements CommandHandlerInterface
{
    public function __construct(
        private PurchasedItemRepositoryInterface $items,
        private ProductAliasRepositoryInterface $aliases,
        private TenantContextInterface $tenantContext,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws \LogicException when the organization is not the one the job runs in, or there are more items than MAX_ITEMS */
    public function __invoke(BackfillProductAliasesCommand $command): void
    {
        if ($this->tenantContext->getOrganization()->getId()->toRfc4122() !== $command->organizationId) {
            throw new \LogicException('Aliases are backfilled only in the organization the job runs in.');
        }
        if (\count($command->itemIds) > BackfillProductAliasesCommand::MAX_ITEMS) {
            throw new \LogicException(\sprintf('At most %d items are backfilled at once.', BackfillProductAliasesCommand::MAX_ITEMS));
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

        /** @var array<string, true> $known */
        $known = [];
        foreach ($this->aliases->findByKeys(array_values($wanted)) as $alias) {
            $known[$alias->getKind()->value.'#'.$alias->getKey()] = true;
        }

        $now = $this->clock->now();
        $created = [];
        foreach ($keysByItem as $itemId => $keys) {
            $item = $byId[$itemId];
            $product = $item->getProduct();
            if (null === $product) {
                continue;
            }
            foreach ($keys as $kind => $key) {
                $index = $kind.'#'.$key;
                if (isset($known[$index])) {
                    continue;
                }
                $known[$index] = true;
                $created[] = new ProductAlias(Uuid::v7(), AliasKind::from($kind), $key, $product, $item->getPackageSize(), $now, $item->getBundleCount());
            }
        }

        if ([] !== $created) {
            $this->aliases->saveAll($created);
        }
        $this->logger->info('Backfilled {created} alias(es) from {items} confirmed item(s).', [
            'organization_id' => $command->organizationId,
            'created' => \count($created),
            'items' => \count($keysByItem),
        ]);
    }
}
