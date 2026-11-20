<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Application\Command\SetDayRate\SetDayRateCommand;
use App\Payroll\Application\Query\GetDayRate\DayRateView;
use App\Payroll\Application\Query\GetDayRate\GetDayRateQuery;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Payroll\DayRateResource;
use Ui\Http\Payroll\SetDayRateInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager gives a member a day rate in the organization selected by the X-Organization-Id
 * header.
 *
 * @implements ProcessorInterface<SetDayRateInput, DayRateResource>
 */
final readonly class SetDayRateProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param SetDayRateInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DayRateResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $rateId = Uuid::v7()->toRfc4122();

        $this->commandBus->handle(new SetDayRateCommand($organizationId, $actorId, $rateId, $data->userId, $data->date, $data->amount));

        /** @var DayRateView $view */
        $view = $this->queryBus->ask(new GetDayRateQuery($organizationId, $actorId, $rateId));

        return DayRateResource::fromView($view);
    }
}
