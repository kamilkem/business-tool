<?php

declare(strict_types=1);

namespace Ui\Http\Income\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Income\Application\Query\GetRevenueEntry\GetRevenueEntryQuery;
use App\Income\Application\Query\GetRevenueEntry\RevenueEntryView;
use App\Income\Domain\Exception\RevenueEntryNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Income\RevenueEntryResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProviderInterface<RevenueEntryResource> */
final readonly class RevenueEntryProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): RevenueEntryResource
    {
        $entryId = $uriVariables['id'];
        \assert(\is_string($entryId));

        /** @var RevenueEntryView|null $view */
        $view = $this->queryBus->ask(new GetRevenueEntryQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $entryId,
        ));

        return RevenueEntryResource::fromView($view ?? throw RevenueEntryNotFoundException::withId($entryId));
    }
}
