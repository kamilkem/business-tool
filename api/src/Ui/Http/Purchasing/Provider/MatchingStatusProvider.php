<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Query\GetMatchingStatus\GetMatchingStatusQuery;
use App\Purchasing\Application\Query\GetMatchingStatus\MatchingStatusView;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Purchasing\MatchingStatusResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * The matching status of the organization selected by the X-Organization-Id header. Employees are refused by
 * the domain (PurchasingAccess).
 *
 * @implements ProviderInterface<MatchingStatusResource>
 */
final readonly class MatchingStatusProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MatchingStatusResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        /** @var MatchingStatusView|null $view */
        $view = $this->queryBus->ask(new GetMatchingStatusQuery($organizationId, $this->currentUser->id()));

        return MatchingStatusResource::fromView($view ?? throw OrganizationNotFoundException::forId($organizationId));
    }
}
