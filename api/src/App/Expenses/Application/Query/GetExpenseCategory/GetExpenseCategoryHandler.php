<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\GetExpenseCategory;

use App\Expenses\Application\ExpensesActor;
use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Repository\ExpenseCategoryRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetExpenseCategoryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ExpenseCategoryRepositoryInterface $categories,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @throws ExpensesManagementForbiddenException when the actor is an employee */
    public function __invoke(GetExpenseCategoryQuery $query): ?ExpenseCategoryView
    {
        if (null === ExpensesActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        $category = Uuid::isValid($query->categoryId) ? $this->categories->findById(Uuid::fromString($query->categoryId)) : null;

        return null === $category ? null : ExpenseCategoryView::fromCategory($category);
    }
}
