<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\CashFlow\Application\Query\GetDayCloseForm\DayCloseFormView;
use App\CashFlow\Application\Query\GetDayCloseForm\GetDayCloseFormQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\CashFlow\DayCloseFormResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProviderInterface<DayCloseFormResource> */
final readonly class DayCloseFormProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DayCloseFormResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        /** @var DayCloseFormView|null $view */
        $view = $this->queryBus->ask(new GetDayCloseFormQuery($organizationId, $this->currentUser->id()));

        return DayCloseFormResource::fromView($view ?? throw OrganizationNotFoundException::forId($organizationId));
    }
}
