<?php

declare(strict_types=1);

namespace Ui\Http\Income\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\State\ProviderInterface;
use App\Income\Application\Query\GetRegister\RegisterView;
use App\Income\Application\Query\ListRegisters\ListRegistersQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Income\RegisterResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * The registers of the organization selected by the X-Organization-Id header: the active ones, or
 * all with ?includeDeactivated=true. Employees are refused by the domain (IncomeAccess).
 *
 * @implements ProviderInterface<RegisterResource>
 */
final readonly class RegisterCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @return list<RegisterResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        $includeDeactivated = true === $operation->getParameters()?->get('includeDeactivated', QueryParameter::class)?->getValue();

        /** @var list<RegisterView>|null $views */
        $views = $this->queryBus->ask(new ListRegistersQuery($organizationId, $this->currentUser->id(), $includeDeactivated));

        return array_map(
            RegisterResource::fromView(...),
            $views ?? throw OrganizationNotFoundException::forId($organizationId),
        );
    }
}
