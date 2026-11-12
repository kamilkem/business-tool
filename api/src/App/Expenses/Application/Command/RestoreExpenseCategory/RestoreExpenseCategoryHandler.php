<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command\RestoreExpenseCategory;

use App\Expenses\Application\ExpensesActor;
use App\Expenses\Domain\Exception\ExpenseCategoryNotFoundException;
use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Repository\ExpenseCategoryRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class RestoreExpenseCategoryHandler implements CommandHandlerInterface
{
    public function __construct(
        private ExpenseCategoryRepositoryInterface $categories,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException        when the actor is not an active member of the organization
     * @throws ExpensesManagementForbiddenException when the actor is not an owner or manager
     * @throws ExpenseCategoryNotFoundException     when the category is not one of the organization's
     */
    public function __invoke(RestoreExpenseCategoryCommand $command): void
    {
        $actor = ExpensesActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $category = Uuid::isValid($command->categoryId) ? $this->categories->findById(Uuid::fromString($command->categoryId)) : null;
        $category ??= throw ExpenseCategoryNotFoundException::withId($command->categoryId);

        $category->restore($actor->id, $actor->role);

        $this->categories->save($category);
    }
}
