<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Query\ListProductProposals\ListProductProposalsQuery;
use App\Purchasing\Application\Query\ListProductProposals\ProductProposalView;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Purchasing\ProductProposalResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * The proposals of the organization selected by the X-Organization-Id header. Employees are refused by
 * the domain (PurchasingAccess).
 *
 * @implements ProviderInterface<ProductProposalResource>
 */
final readonly class ProductProposalCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @return list<ProductProposalResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        /** @var list<ProductProposalView>|null $views */
        $views = $this->queryBus->ask(new ListProductProposalsQuery($organizationId, $this->currentUser->id()));

        return array_map(
            ProductProposalResource::fromView(...),
            $views ?? throw OrganizationNotFoundException::forId($organizationId),
        );
    }
}
