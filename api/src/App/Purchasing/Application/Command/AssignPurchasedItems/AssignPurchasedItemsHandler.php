<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\AssignPurchasedItems;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Exception\InvalidBundleCountException;
use App\Purchasing\Domain\Exception\InvalidPackageSizeException;
use App\Purchasing\Domain\Exception\PurchasedItemNotFoundException;
use App\Purchasing\Domain\Exception\PurchasedItemProductNotFoundException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Event\PurchasedItemsConfirmed;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Event\EventPublisherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Links the items to the product in one flush. It checks every item and the product first, and
 * changes nothing when one is missing (another organization's is missing too). The items are one
 * action of the person, not one aggregate each, so this is the one command that changes many.
 */
final readonly class AssignPurchasedItemsHandler implements CommandHandlerInterface
{
    public function __construct(
        private PurchasedItemRepositoryInterface $items,
        private ProductRepositoryInterface $products,
        private QueryBusInterface $queryBus,
        private EventPublisherInterface $events,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException            when the actor is not an active member of the organization
     * @throws PurchasingManagementForbiddenException   when the actor is not an owner or manager
     * @throws PurchasedItemNotFoundException           when an item is not one of the organization's
     * @throws PurchasedItemProductNotFoundException    when the product is not one of the organization's
     * @throws InvalidPackageSizeException              when the package size is not a positive decimal
     * @throws InvalidBundleCountException              when the bundle count is out of range, has no package size, or makes the size per unit too large
     * @throws \InvalidArgumentException                when there are more items than AssignPurchasedItemsCommand::MAX_ITEMS
     */
    public function __invoke(AssignPurchasedItemsCommand $command): void
    {
        $actor = PurchasingActor::resolve($this->queryBus, $command->organizationId, $command->actorId);
        if (\count($command->itemIds) > AssignPurchasedItemsCommand::MAX_ITEMS) {
            throw new \InvalidArgumentException(\sprintf('At most %d items are assigned at once.', AssignPurchasedItemsCommand::MAX_ITEMS));
        }

        if (null !== $command->bundleCount && null === $command->packageSize) {
            throw InvalidBundleCountException::withoutPackageSize($command->bundleCount);
        }

        $product = Uuid::isValid($command->productId) ? $this->products->findById(Uuid::fromString($command->productId)) : null;
        $product ??= throw PurchasedItemProductNotFoundException::withId($command->productId);

        $wanted = [];
        foreach ($command->itemIds as $itemId) {
            $wanted[$itemId] = Uuid::isValid($itemId) ? Uuid::fromString($itemId) : throw PurchasedItemNotFoundException::withId($itemId);
        }
        $found = [];
        foreach ($this->items->findByIds(array_values($wanted)) as $item) {
            $found[$item->getId()->toRfc4122()] = $item;
        }
        foreach ($wanted as $itemId => $id) {
            if (!isset($found[$id->toRfc4122()])) {
                throw PurchasedItemNotFoundException::withId($itemId);
            }
        }

        // Every item is validated by link() before the flush, and the flush is one transaction.
        $items = array_values($found);
        foreach ($items as $item) {
            // A package size sent goes with its own count (null: no bundle); with none, the item keeps its size and its count.
            $item->link(
                $product,
                $command->packageSize ?? $item->getPackageSize(),
                $actor->id,
                $actor->role,
                null === $command->packageSize ? $item->getBundleCount() : $command->bundleCount,
            );
        }

        $this->items->saveAll($items);
        if ([] !== $items) {
            $this->events->publish(new PurchasedItemsConfirmed($command->organizationId, array_map(static fn (PurchasedItem $item): string => $item->getId()->toRfc4122(), $items)));
        }
    }
}
