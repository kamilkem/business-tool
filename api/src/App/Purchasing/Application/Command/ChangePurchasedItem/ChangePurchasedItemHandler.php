<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\ChangePurchasedItem;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Exception\InvalidPackageSizeException;
use App\Purchasing\Domain\Exception\PurchasedItemNotFoundException;
use App\Purchasing\Domain\Exception\PurchasedItemProductNotFoundException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ChangePurchasedItemHandler implements CommandHandlerInterface
{
    public function __construct(
        private PurchasedItemRepositoryInterface $items,
        private ProductRepositoryInterface $products,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException            when the actor is not an active member of the organization
     * @throws PurchasingManagementForbiddenException   when the actor is not an owner or manager
     * @throws PurchasedItemNotFoundException           when the item is not one of the organization's
     * @throws PurchasedItemProductNotFoundException    when the product is not one of the organization's
     * @throws InvalidPackageSizeException              when the package size is not a positive decimal
     */
    public function __invoke(ChangePurchasedItemCommand $command): void
    {
        $actor = PurchasingActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $item = Uuid::isValid($command->itemId) ? $this->items->findById(Uuid::fromString($command->itemId)) : null;
        $item ??= throw PurchasedItemNotFoundException::withId($command->itemId);

        $product = null;
        if (null !== $command->productId) {
            $product = Uuid::isValid($command->productId) ? $this->products->findById(Uuid::fromString($command->productId)) : null;
            $product ??= throw PurchasedItemProductNotFoundException::withId($command->productId);
        }

        $item->link($product, $command->packageSize, $actor->id, $actor->role);

        $this->items->save($item);
    }
}
