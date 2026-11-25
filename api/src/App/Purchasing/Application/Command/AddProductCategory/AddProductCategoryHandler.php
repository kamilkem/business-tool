<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\AddProductCategory;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Exception\InvalidProductCategoryNameException;
use App\Purchasing\Domain\Exception\ProductCategoryNameTakenException;
use App\Purchasing\Domain\Exception\ProductCategoryParentNotFoundException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Model\ProductCategory;
use App\Purchasing\Domain\Repository\ProductCategoryRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class AddProductCategoryHandler implements CommandHandlerInterface
{
    public function __construct(
        private ProductCategoryRepositoryInterface $categories,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException            when the actor is not an active member of the organization
     * @throws PurchasingManagementForbiddenException   when the actor is not an owner or manager
     * @throws ProductCategoryParentNotFoundException   when the parent is not one of the organization's
     * @throws InvalidProductCategoryNameException      when the name is blank or too long
     * @throws ProductCategoryNameTakenException        when a category under the same parent has the name
     */
    public function __invoke(AddProductCategoryCommand $command): void
    {
        $actor = PurchasingActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $parent = null;
        if (null !== $command->parentId) {
            $parent = Uuid::isValid($command->parentId) ? $this->categories->findById(Uuid::fromString($command->parentId)) : null;
            $parent ??= throw ProductCategoryParentNotFoundException::withId($command->parentId);
        }

        $category = ProductCategory::create(
            Uuid::fromString($command->categoryId),
            $command->name,
            $parent,
            $this->categories->findSibling($parent?->getId(), $command->name),
            $actor->id,
            $actor->role,
            $this->clock->now(),
        );

        $this->categories->add($category);
    }
}
