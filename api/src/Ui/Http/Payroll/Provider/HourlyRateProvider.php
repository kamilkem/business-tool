<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Application\Query\GetHourlyRate\HourlyRateView;
use App\Payroll\Application\Query\GetHourlyRate\GetHourlyRateQuery;
use App\Payroll\Domain\Exception\PayRateNotFoundException;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Payroll\HourlyRateResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProviderInterface<HourlyRateResource> */
final readonly class HourlyRateProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): HourlyRateResource
    {
        $rateId = $uriVariables['id'];
        \assert(\is_string($rateId));

        /** @var HourlyRateView|null $view */
        $view = $this->queryBus->ask(new GetHourlyRateQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $rateId,
        ));

        return HourlyRateResource::fromView($view ?? throw PayRateNotFoundException::hourlyRate($rateId));
    }
}
