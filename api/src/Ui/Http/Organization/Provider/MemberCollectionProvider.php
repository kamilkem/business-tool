<?php

declare(strict_types=1);

namespace Ui\Http\Organization\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Application\Query\ListMembers\ListMembersQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Organization\MemberResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Lists the members of the organization selected by the X-Organization-Id header (400 without
 * it). Employees are refused by the domain (Organization::membersVisibleTo()).
 *
 * @implements ProviderInterface<MemberResource>
 */
final readonly class MemberCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @return list<MemberResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        /** @var list<MemberView>|null $views */
        $views = $this->queryBus->ask(new ListMembersQuery($organizationId, $this->currentUser->id()));

        return array_map(
            MemberResource::fromView(...),
            $views ?? throw OrganizationNotFoundException::forId($organizationId),
        );
    }
}
