<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\CashFlow\Application\Query\GetDayClose\DayCloseView;
use App\CashFlow\Application\Query\GetDayClose\GetDayCloseQuery;
use App\CashFlow\Domain\Exception\DayCloseNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\CashFlow\DayCloseResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProviderInterface<DayCloseResource> */
final readonly class DayCloseProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DayCloseResource
    {
        $dayCloseId = $uriVariables['id'];
        \assert(\is_string($dayCloseId));

        /** @var DayCloseView|null $view */
        $view = $this->queryBus->ask(new GetDayCloseQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $dayCloseId,
        ));

        return DayCloseResource::fromView($view ?? throw DayCloseNotFoundException::withId($dayCloseId));
    }
}
