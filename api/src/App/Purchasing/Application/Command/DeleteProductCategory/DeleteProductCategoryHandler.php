<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\DeleteProductCategory;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Exception\ProductCategoryInUseException;
use App\Purchasing\Domain\Exception\ProductCategoryNotFoundException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Repository\ProductCategoryRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DeleteProductCategoryHandler implements CommandHandlerInterface
{
    public function __construct(
        private ProductCategoryRepositoryInterface $categories,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException          when the actor is not an active member of the organization
     * @throws PurchasingManagementForbiddenException when the actor is not an owner or manager
     * @throws ProductCategoryNotFoundException       when the category is not one of the organization's
     * @throws ProductCategoryInUseException          when the category has a subcategory or a product
     */
    public function __invoke(DeleteProductCategoryCommand $command): void
    {
        $actor = PurchasingActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $category = Uuid::isValid($command->categoryId) ? $this->categories->findById(Uuid::fromString($command->categoryId)) : null;
        $category ??= throw ProductCategoryNotFoundException::withId($command->categoryId);

        // Products arrive with #241, which asks its repository here whether one has the category.
        $category->assertDeletable($this->categories->hasSubcategories($category->getId()), false, $actor->id, $actor->role);

        // The foreign keys of subcategories (and later of products) catch one added in the meantime.
        $this->categories->remove($category);
    }
}
