<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command\AddExpenseCategory;

use App\Expenses\Application\ExpensesActor;
use App\Expenses\Domain\Exception\ExpenseCategoryNameTakenException;
use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Exception\InvalidExpenseCategoryNameException;
use App\Expenses\Domain\Model\ExpenseCategory;
use App\Expenses\Domain\Repository\ExpenseCategoryRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class AddExpenseCategoryHandler implements CommandHandlerInterface
{
    public function __construct(
        private ExpenseCategoryRepositoryInterface $categories,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException        when the actor is not an active member of the organization
     * @throws ExpensesManagementForbiddenException when the actor is not an owner or manager
     * @throws InvalidExpenseCategoryNameException  when the name is blank or too long
     * @throws ExpenseCategoryNameTakenException    when a category of the organization, archived or not, has the name
     */
    public function __invoke(AddExpenseCategoryCommand $command): void
    {
        $actor = ExpensesActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $category = ExpenseCategory::create(
            Uuid::fromString($command->categoryId),
            $command->name,
            $this->categories->findByName(trim($command->name)),
            $actor->id,
            $actor->role,
            $this->clock->now(),
        );

        $this->categories->add($category);
    }
}
