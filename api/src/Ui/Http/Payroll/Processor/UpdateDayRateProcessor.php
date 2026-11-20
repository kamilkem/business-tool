<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Application\Command\ChangeDayRate\ChangeDayRateCommand;
use App\Payroll\Application\Query\GetDayRate\DayRateView;
use App\Payroll\Application\Query\GetDayRate\GetDayRateQuery;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Payroll\DayRateResource;
use Ui\Http\Payroll\UpdateDayRateInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager corrects a day rate of the organization selected by the X-Organization-Id
 * header.
 *
 * @implements ProcessorInterface<UpdateDayRateInput, DayRateResource>
 */
final readonly class UpdateDayRateProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param UpdateDayRateInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DayRateResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $rateId = $uriVariables['id'];
        \assert(\is_string($rateId) && null !== $data->date && null !== $data->amount);

        $this->commandBus->handle(new ChangeDayRateCommand($organizationId, $actorId, $rateId, $data->date, $data->amount));

        /** @var DayRateView $view */
        $view = $this->queryBus->ask(new GetDayRateQuery($organizationId, $actorId, $rateId));

        return DayRateResource::fromView($view);
    }
}
