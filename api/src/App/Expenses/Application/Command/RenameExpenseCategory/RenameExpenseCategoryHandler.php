<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command\RenameExpenseCategory;

use App\Expenses\Application\ExpensesActor;
use App\Expenses\Domain\Exception\ExpenseCategoryNameTakenException;
use App\Expenses\Domain\Exception\ExpenseCategoryNotFoundException;
use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Exception\InvalidExpenseCategoryNameException;
use App\Expenses\Domain\Repository\ExpenseCategoryRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class RenameExpenseCategoryHandler implements CommandHandlerInterface
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
     * @throws InvalidExpenseCategoryNameException  when the name is blank or too long
     * @throws ExpenseCategoryNameTakenException    when another category of the organization has the name
     */
    public function __invoke(RenameExpenseCategoryCommand $command): void
    {
        $actor = ExpensesActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $category = Uuid::isValid($command->categoryId) ? $this->categories->findById(Uuid::fromString($command->categoryId)) : null;
        $category ??= throw ExpenseCategoryNotFoundException::withId($command->categoryId);

        $category->rename($command->name, $this->categories->findByName(trim($command->name)), $actor->id, $actor->role);

        $this->categories->save($category);
    }
}
