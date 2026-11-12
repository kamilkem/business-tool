<?php

declare(strict_types=1);

namespace Ui\Http\Expenses\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\State\ProviderInterface;
use App\Expenses\Application\Query\GetExpenseCategory\ExpenseCategoryView;
use App\Expenses\Application\Query\ListExpenseCategories\ListExpenseCategoriesQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Expenses\ExpenseCategoryResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * The expense categories of the organization selected by the X-Organization-Id header: the active
 * ones, or all with ?includeArchived=true. Employees are refused by the domain (ExpensesAccess).
 *
 * @implements ProviderInterface<ExpenseCategoryResource>
 */
final readonly class ExpenseCategoryCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @return list<ExpenseCategoryResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        $includeArchived = true === $operation->getParameters()?->get('includeArchived', QueryParameter::class)?->getValue();

        /** @var list<ExpenseCategoryView>|null $views */
        $views = $this->queryBus->ask(new ListExpenseCategoriesQuery($organizationId, $this->currentUser->id(), $includeArchived));

        return array_map(
            ExpenseCategoryResource::fromView(...),
            $views ?? throw OrganizationNotFoundException::forId($organizationId),
        );
    }
}
