<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Application\Query\ListPayrollRuns\ListPayrollRunsQuery;
use App\Payroll\Application\Query\ListPayrollRuns\PayrollRunSummaryView;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Payroll\PayrollRunSummaryResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProviderInterface<PayrollRunSummaryResource> */
final readonly class PayrollRunSummaryCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @return list<PayrollRunSummaryResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        /** @var list<PayrollRunSummaryView>|null $views */
        $views = $this->queryBus->ask(new ListPayrollRunsQuery($organizationId, $this->currentUser->id()));

        return array_map(
            PayrollRunSummaryResource::fromView(...),
            $views ?? throw OrganizationNotFoundException::forId($organizationId),
        );
    }
}
