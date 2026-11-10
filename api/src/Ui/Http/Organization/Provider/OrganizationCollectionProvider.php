<?php

declare(strict_types=1);

namespace Ui\Http\Organization\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Organization\Application\Query\ListUserOrganizations\ListUserOrganizationsQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Organization\OrganizationResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProviderInterface<OrganizationResource> */
final readonly class OrganizationCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
    ) {
    }

    /** @return list<OrganizationResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        /** @var list<OrganizationView> $views */
        $views = $this->queryBus->ask(new ListUserOrganizationsQuery($this->currentUser->id()));

        return array_map(OrganizationResource::fromView(...), $views);
    }
}
