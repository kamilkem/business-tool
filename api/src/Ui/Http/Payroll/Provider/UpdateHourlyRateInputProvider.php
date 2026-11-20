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
use Ui\Http\Payroll\UpdateHourlyRateInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Pre-fills the merge-patch input with the hourly rate's current values; API Platform then applies the
 * request body onto it. A hourly rate that is not the organization's is a 404 here; an employee is refused
 * by the command (403).
 *
 * @implements ProviderInterface<UpdateHourlyRateInput>
 */
final readonly class UpdateHourlyRateInputProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UpdateHourlyRateInput
    {
        $rateId = $uriVariables['id'];
        \assert(\is_string($rateId));

        /** @var HourlyRateView|null $view */
        $view = $this->queryBus->ask(new GetHourlyRateQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $rateId,
        ));

        return UpdateHourlyRateInput::fromView($view ?? throw PayRateNotFoundException::hourlyRate($rateId));
    }
}
