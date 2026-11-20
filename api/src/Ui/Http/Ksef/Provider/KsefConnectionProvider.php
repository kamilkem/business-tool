<?php

declare(strict_types=1);

namespace Ui\Http\Ksef\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Ksef\Application\Query\GetKsefConnection\GetKsefConnectionQuery;
use App\Ksef\Application\Query\GetKsefConnection\KsefConnectionView;
use App\Ksef\Domain\Exception\KsefConnectionNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Ksef\KsefConnectionResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProviderInterface<KsefConnectionResource> */
final readonly class KsefConnectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): KsefConnectionResource
    {
        /** @var KsefConnectionView|null $view */
        $view = $this->queryBus->ask(new GetKsefConnectionQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
        ));

        return KsefConnectionResource::fromView($view ?? throw KsefConnectionNotFoundException::create());
    }
}
