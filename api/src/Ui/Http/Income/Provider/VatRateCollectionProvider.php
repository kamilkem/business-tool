<?php

declare(strict_types=1);

namespace Ui\Http\Income\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Income\Application\Query\ListVatRates\ListVatRatesQuery;
use App\Income\Application\Query\ListVatRates\VatRateView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Income\VatRateResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProviderInterface<VatRateResource> */
final readonly class VatRateCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @return list<VatRateResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        /** @var list<VatRateView>|null $views */
        $views = $this->queryBus->ask(new ListVatRatesQuery($organizationId, $this->currentUser->id()));
        $views ??= throw OrganizationNotFoundException::forId($organizationId);

        return array_map(VatRateResource::fromView(...), $views);
    }
}
