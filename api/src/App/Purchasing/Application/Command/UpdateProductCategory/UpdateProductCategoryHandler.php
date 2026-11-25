<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\UpdateProductCategory;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Exception\InvalidProductCategoryNameException;
use App\Purchasing\Domain\Exception\ProductCategoryCycleException;
use App\Purchasing\Domain\Exception\ProductCategoryNameTakenException;
use App\Purchasing\Domain\Exception\ProductCategoryNotFoundException;
use App\Purchasing\Domain\Exception\ProductCategoryParentNotFoundException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Repository\ProductCategoryRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class UpdateProductCategoryHandler implements CommandHandlerInterface
{
    public function __construct(
        private ProductCategoryRepositoryInterface $categories,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException            when the actor is not an active member of the organization
     * @throws PurchasingManagementForbiddenException   when the actor is not an owner or manager
     * @throws ProductCategoryNotFoundException         when the category is not one of the organization's
     * @throws ProductCategoryParentNotFoundException   when the new parent is not one of the organization's
     * @throws InvalidProductCategoryNameException      when the name is blank or too long
     * @throws ProductCategoryCycleException            when the new parent is the category or one of its descendants
     * @throws ProductCategoryNameTakenException        when another category under the new parent has the name
     */
    public function __invoke(UpdateProductCategoryCommand $command): void
    {
        $actor = PurchasingActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $category = Uuid::isValid($command->categoryId) ? $this->categories->findById(Uuid::fromString($command->categoryId)) : null;
        $category ??= throw ProductCategoryNotFoundException::withId($command->categoryId);

        $parent = null;
        if (null !== $command->parentId) {
            $parent = Uuid::isValid($command->parentId) ? $this->categories->findById(Uuid::fromString($command->parentId)) : null;
            $parent ??= throw ProductCategoryParentNotFoundException::withId($command->parentId);
        }

        $category->change(
            $command->name,
            $parent,
            $this->categories->findSibling($parent?->getId(), $command->name),
            $actor->id,
            $actor->role,
        );

        $this->categories->save($category);
    }
}
