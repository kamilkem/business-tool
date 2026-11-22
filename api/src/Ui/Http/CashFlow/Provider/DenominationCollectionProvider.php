<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\State\ProviderInterface;
use App\CashFlow\Application\Query\GetDenomination\DenominationView;
use App\CashFlow\Application\Query\ListDenominations\ListDenominationsQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\CashFlow\DenominationResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * The denominations of the organization selected by the X-Organization-Id header, in its currency:
 * the active ones, or all with ?includeDeactivated=true.
 *
 * @implements ProviderInterface<DenominationResource>
 */
final readonly class DenominationCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @return list<DenominationResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        $includeDeactivated = true === $operation->getParameters()?->get('includeDeactivated', QueryParameter::class)?->getValue();

        /** @var list<DenominationView>|null $views */
        $views = $this->queryBus->ask(new ListDenominationsQuery($organizationId, $this->currentUser->id(), $includeDeactivated));

        return array_map(
            DenominationResource::fromView(...),
            $views ?? throw OrganizationNotFoundException::forId($organizationId),
        );
    }
}
