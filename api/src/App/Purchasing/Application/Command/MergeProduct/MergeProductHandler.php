<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\MergeProduct;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Exception\ProductInUseException;
use App\Purchasing\Domain\Exception\ProductMergeIntoItselfException;
use App\Purchasing\Domain\Exception\ProductMergeTargetNotFoundException;
use App\Purchasing\Domain\Exception\ProductNotFoundException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Moves the items of a product to another one and deletes it. The product is the aggregate this
 * changes; the items are moved in one statement and are not loaded. The aliases matching keeps per
 * product (#233) will move here too. If the product cannot be deleted after the move (an item was
 * linked to it in the meantime), the items are already on the target and a retry finishes the merge.
 */
final readonly class MergeProductHandler implements CommandHandlerInterface
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private PurchasedItemRepositoryInterface $items,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException            when the actor is not an active member of the organization
     * @throws PurchasingManagementForbiddenException   when the actor is not an owner or manager
     * @throws ProductNotFoundException                 when the product is not one of the organization's
     * @throws ProductMergeTargetNotFoundException      when the product to merge into is not one of the organization's
     * @throws ProductMergeIntoItselfException          when the target is the product itself
     * @throws ProductInUseException                    when an item was linked to the product while it was merged
     */
    public function __invoke(MergeProductCommand $command): void
    {
        $actor = PurchasingActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $product = Uuid::isValid($command->productId) ? $this->products->findById(Uuid::fromString($command->productId)) : null;
        $product ??= throw ProductNotFoundException::withId($command->productId);
        $target = Uuid::isValid($command->intoProductId) ? $this->products->findById(Uuid::fromString($command->intoProductId)) : null;
        $target ??= throw ProductMergeTargetNotFoundException::withId($command->intoProductId);

        $product->assertCanMergeInto($target, $actor->id, $actor->role);

        $this->items->moveItems($product->getId(), $target->getId());
        $this->products->remove($product);
    }
}
