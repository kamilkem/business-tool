<?php

declare(strict_types=1);

namespace Ui\Http\Expenses\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Expenses\Application\Query\GetExpenseCategory\ExpenseCategoryView;
use App\Expenses\Application\Query\GetExpenseCategory\GetExpenseCategoryQuery;
use App\Expenses\Domain\Exception\ExpenseCategoryNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Expenses\ExpenseCategoryResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProviderInterface<ExpenseCategoryResource> */
final readonly class ExpenseCategoryProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ExpenseCategoryResource
    {
        $categoryId = $uriVariables['id'];
        \assert(\is_string($categoryId));

        /** @var ExpenseCategoryView|null $view */
        $view = $this->queryBus->ask(new GetExpenseCategoryQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $categoryId,
        ));

        return ExpenseCategoryResource::fromView($view ?? throw ExpenseCategoryNotFoundException::withId($categoryId));
    }
}
