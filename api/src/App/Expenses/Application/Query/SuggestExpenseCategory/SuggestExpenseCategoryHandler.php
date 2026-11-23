<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\SuggestExpenseCategory;

use App\Expenses\Application\ExpensesActor;
use App\Expenses\Application\SellerCategorySuggestion;
use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class SuggestExpenseCategoryHandler implements QueryHandlerInterface
{
    public function __construct(
        private SellerCategorySuggestion $suggestion,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * Null when the actor is not an active member of the organization.
     *
     * @throws ExpensesManagementForbiddenException when the actor is an employee
     */
    public function __invoke(SuggestExpenseCategoryQuery $query): ?ExpenseCategorySuggestionView
    {
        if (null === ExpensesActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        return ExpenseCategorySuggestionView::of($this->suggestion->forSeller($query->sellerTaxId, $query->sellerName));
    }
}
