<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Application\Command\SetHourlyRate\SetHourlyRateCommand;
use App\Payroll\Application\Query\GetHourlyRate\HourlyRateView;
use App\Payroll\Application\Query\GetHourlyRate\GetHourlyRateQuery;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Payroll\HourlyRateResource;
use Ui\Http\Payroll\SetHourlyRateInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager gives a member a hourly rate in the organization selected by the X-Organization-Id
 * header.
 *
 * @implements ProcessorInterface<SetHourlyRateInput, HourlyRateResource>
 */
final readonly class SetHourlyRateProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param SetHourlyRateInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): HourlyRateResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $rateId = Uuid::v7()->toRfc4122();

        $this->commandBus->handle(new SetHourlyRateCommand($organizationId, $actorId, $rateId, $data->userId, $data->effectiveFrom, $data->amount));

        /** @var HourlyRateView $view */
        $view = $this->queryBus->ask(new GetHourlyRateQuery($organizationId, $actorId, $rateId));

        return HourlyRateResource::fromView($view);
    }
}
