<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\ListExpenseCategories;

use App\Expenses\Application\ExpensesActor;
use App\Expenses\Application\Query\GetExpenseCategory\ExpenseCategoryView;
use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Repository\ExpenseCategoryRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class ListExpenseCategoriesHandler implements QueryHandlerInterface
{
    public function __construct(
        private ExpenseCategoryRepositoryInterface $categories,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @return list<ExpenseCategoryView>|null active categories first, then by name
     *
     * @throws ExpensesManagementForbiddenException when the actor is an employee
     */
    public function __invoke(ListExpenseCategoriesQuery $query): ?array
    {
        if (null === ExpensesActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        return array_map(
            ExpenseCategoryView::fromCategory(...),
            $this->categories->findAll($query->includeArchived),
        );
    }
}
