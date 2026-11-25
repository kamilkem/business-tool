<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\DeleteProduct;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Exception\ProductInUseException;
use App\Purchasing\Domain\Exception\ProductNotFoundException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DeleteProductHandler implements CommandHandlerInterface
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private PurchasedItemRepositoryInterface $items,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException          when the actor is not an active member of the organization
     * @throws PurchasingManagementForbiddenException when the actor is not an owner or manager
     * @throws ProductNotFoundException               when the product is not one of the organization's
     * @throws ProductInUseException                  when a purchased item refers to the product
     */
    public function __invoke(DeleteProductCommand $command): void
    {
        $actor = PurchasingActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $product = Uuid::isValid($command->productId) ? $this->products->findById(Uuid::fromString($command->productId)) : null;
        $product ??= throw ProductNotFoundException::withId($command->productId);

        $product->assertDeletable($this->items->hasItemsOfProduct($product->getId()), $actor->id, $actor->role);

        // The foreign key of the items catches one linked in the meantime.
        $this->products->remove($product);
    }
}
