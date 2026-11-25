<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\CreateProduct;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Exception\InvalidProductNameException;
use App\Purchasing\Domain\Exception\InvalidProductReferenceQuantityException;
use App\Purchasing\Domain\Exception\ProductNameTakenException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Exception\UnknownProductCategoryException;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Repository\ProductCategoryRepositoryInterface;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CreateProductHandler implements CommandHandlerInterface
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private ProductCategoryRepositoryInterface $categories,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException              when the actor is not an active member of the organization
     * @throws PurchasingManagementForbiddenException     when the actor is not an owner or manager
     * @throws UnknownProductCategoryException            when the category is not one of the organization's
     * @throws InvalidProductNameException                when the name is blank or too long
     * @throws InvalidProductReferenceQuantityException   when the reference quantity is not positive, or is given for `none`
     * @throws ProductNameTakenException                  when another product has the name
     */
    public function __invoke(CreateProductCommand $command): void
    {
        $actor = PurchasingActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $category = null;
        if (null !== $command->categoryId) {
            $category = Uuid::isValid($command->categoryId) ? $this->categories->findById(Uuid::fromString($command->categoryId)) : null;
            $category ??= throw UnknownProductCategoryException::withId($command->categoryId);
        }

        $product = Product::create(
            Uuid::fromString($command->productId),
            $command->name,
            $category,
            ProductMeasure::from($command->measure),
            $command->referenceQuantity,
            $command->excluded,
            $this->products->findByName($command->name),
            $actor->id,
            $actor->role,
            $this->clock->now(),
        );

        $this->products->add($product);
    }
}
