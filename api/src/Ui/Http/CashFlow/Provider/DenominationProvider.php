<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\CashFlow\Application\Query\GetDenomination\DenominationView;
use App\CashFlow\Application\Query\GetDenomination\GetDenominationQuery;
use App\CashFlow\Domain\Exception\DenominationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\CashFlow\DenominationResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProviderInterface<DenominationResource> */
final readonly class DenominationProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DenominationResource
    {
        $denominationId = $uriVariables['id'];
        \assert(\is_string($denominationId));

        /** @var DenominationView|null $view */
        $view = $this->queryBus->ask(new GetDenominationQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $denominationId,
        ));

        return DenominationResource::fromView($view ?? throw DenominationNotFoundException::withId($denominationId));
    }
}
