<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Application\Command\ChangeHourlyRate\ChangeHourlyRateCommand;
use App\Payroll\Application\Query\GetHourlyRate\HourlyRateView;
use App\Payroll\Application\Query\GetHourlyRate\GetHourlyRateQuery;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Payroll\HourlyRateResource;
use Ui\Http\Payroll\UpdateHourlyRateInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager corrects a hourly rate of the organization selected by the X-Organization-Id
 * header.
 *
 * @implements ProcessorInterface<UpdateHourlyRateInput, HourlyRateResource>
 */
final readonly class UpdateHourlyRateProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param UpdateHourlyRateInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): HourlyRateResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $rateId = $uriVariables['id'];
        \assert(\is_string($rateId) && null !== $data->effectiveFrom && null !== $data->amount);

        $this->commandBus->handle(new ChangeHourlyRateCommand($organizationId, $actorId, $rateId, $data->effectiveFrom, $data->amount));

        /** @var HourlyRateView $view */
        $view = $this->queryBus->ask(new GetHourlyRateQuery($organizationId, $actorId, $rateId));

        return HourlyRateResource::fromView($view);
    }
}
