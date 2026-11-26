<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\UpdateProduct;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Event\ProductChanged;
use App\Purchasing\Domain\Exception\InvalidProductNameException;
use App\Purchasing\Domain\Exception\InvalidProductReferenceQuantityException;
use App\Purchasing\Domain\Exception\ProductNameTakenException;
use App\Purchasing\Domain\Exception\ProductNotFoundException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Exception\UnknownProductCategoryException;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Repository\ProductCategoryRepositoryInterface;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Event\EventPublisherInterface;
use Symfony\Component\Uid\Uuid;

final readonly class UpdateProductHandler implements CommandHandlerInterface
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private ProductCategoryRepositoryInterface $categories,
        private QueryBusInterface $queryBus,
        private EventPublisherInterface $events,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException              when the actor is not an active member of the organization
     * @throws PurchasingManagementForbiddenException     when the actor is not an owner or manager
     * @throws ProductNotFoundException                   when the product is not one of the organization's
     * @throws UnknownProductCategoryException            when the category is not one of the organization's
     * @throws InvalidProductNameException                when the name is blank or too long
     * @throws InvalidProductReferenceQuantityException   when the reference quantity is not positive, or is given for `none`
     * @throws ProductNameTakenException                  when another product has the name
     */
    public function __invoke(UpdateProductCommand $command): void
    {
        $actor = PurchasingActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $product = Uuid::isValid($command->productId) ? $this->products->findById(Uuid::fromString($command->productId)) : null;
        $product ??= throw ProductNotFoundException::withId($command->productId);

        $category = null;
        if (null !== $command->categoryId) {
            $category = Uuid::isValid($command->categoryId) ? $this->categories->findById(Uuid::fromString($command->categoryId)) : null;
            $category ??= throw UnknownProductCategoryException::withId($command->categoryId);
        }

        $name = $product->getName();
        $measure = $product->getMeasure();
        $product->change(
            $command->name,
            $category,
            ProductMeasure::from($command->measure),
            $command->referenceQuantity,
            $command->excluded,
            $this->products->findByName($command->name),
            $actor->id,
            $actor->role,
        );

        $this->products->save($product);

        // What the model is shown of a product (ADR 0046 §1): nothing else queues matching.
        $renamed = $product->getName() !== $name;
        $measureChanged = $product->getMeasure() !== $measure;
        if ($renamed || $measureChanged) {
            $this->events->publish(new ProductChanged($command->organizationId, $command->productId, $renamed, $measureChanged));
        }
    }
}
