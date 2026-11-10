<?php

declare(strict_types=1);

namespace Ui\Http\Organization\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Organization\OrganizationResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProviderInterface<OrganizationResource> */
final readonly class GetOrganizationProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): OrganizationResource
    {
        $id = $uriVariables['id'];
        \assert(\is_string($id));

        /** @var OrganizationView|null $view */
        $view = $this->queryBus->ask(new GetOrganizationQuery($id, $this->currentUser->id()));

        return OrganizationResource::fromView($view ?? throw OrganizationNotFoundException::forId($id));
    }
}
